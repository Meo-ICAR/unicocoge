<?php

namespace App\Services\ProformaBanca;

use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use RuntimeException;

/**
 * Legge il dettaglio delle pratiche di una banca. CSV ed Excel si leggono dalle colonne (intestazioni riconosciute per sinonimo),
 * immagini e PDF li legge l'AI e restituisce le stesse righe.
 */
class LettoreProformaBanca
{
    /** Intestazioni riconosciute (senza maiuscole, spazi e punteggiatura) per ogni dato della riga. */
    private const INTESTAZIONI = [
        'codice_pratica' => ['codicepraticabanca', 'codicepratica', 'numeropratica', 'npratica', 'pratica', 'idpratica', 'numerocontratto', 'contratto', 'riferimento'],
        'codice_fiscale' => ['codicefiscale', 'cf', 'codfisc', 'codicefiscalecliente'],
        'cognome' => ['cognome', 'cognomecliente'],
        'nome' => ['nome', 'nomecliente'],
        'nominativo' => ['nominativo', 'cliente', 'intestatario', 'richiedente', 'denominazione'],
        'importo' => ['provvigione', 'provvigioni', 'compenso', 'importoprovvigione', 'importocompenso', 'commissione', 'commissioni', 'importo', 'totale'],
    ];

    /**
     * @return list<RigaProformaBanca>
     */
    public function leggi(string $contenuto, string $nomeFile, string $mime): array
    {
        $estensione = strtolower(pathinfo($nomeFile, PATHINFO_EXTENSION));

        if (in_array($estensione, ['csv', 'txt'], true)) {
            return $this->righe($this->righeCsv($contenuto));
        }

        if (in_array($estensione, ['xlsx', 'xls', 'ods'], true)) {
            return $this->righe($this->righeExcel($contenuto, $estensione));
        }

        return $this->leggiConAi($contenuto, $mime);
    }

    /** Numero in formato italiano o inglese («1.234,56», «1234.56», «€ 12,5»). */
    public static function numero(mixed $valore): ?float
    {
        if (is_int($valore) || is_float($valore)) {
            return round((float) $valore, 2);
        }

        $testo = preg_replace('/[^\d,.\-]/', '', (string) $valore) ?? '';

        if ($testo === '' || ! preg_match('/\d/', $testo)) {
            return null;
        }

        $virgola = strrpos($testo, ',');
        $punto = strrpos($testo, '.');

        if ($virgola !== false && $punto !== false) {
            $testo = $virgola > $punto ? str_replace(',', '.', str_replace('.', '', $testo)) : str_replace(',', '', $testo);
        } elseif ($virgola !== false) {
            $testo = str_replace(',', '.', $testo);
        } elseif ($punto !== false && preg_match('/\.\d{3}$/', $testo) && substr_count($testo, '.') > 1) {
            $testo = str_replace('.', '', $testo);
        }

        return is_numeric($testo) ? round((float) $testo, 2) : null;
    }

    /** @return list<list<mixed>> */
    private function righeCsv(string $contenuto): array
    {
        $contenuto = preg_replace('/^\xEF\xBB\xBF/', '', $contenuto) ?? $contenuto;

        if (! mb_check_encoding($contenuto, 'UTF-8')) {
            $contenuto = mb_convert_encoding($contenuto, 'UTF-8', 'ISO-8859-1');
        }

        $primaRiga = strtok($contenuto, "\n") ?: '';
        $separatore = collect([';', ',', "\t", '|'])->sortByDesc(fn (string $s): int => substr_count($primaRiga, $s))->first();

        return array_map(
            fn (string $riga): array => str_getcsv($riga, $separatore, '"', ''),
            array_values(array_filter(preg_split('/\R/', $contenuto) ?: [], fn (string $riga): bool => trim($riga) !== '')),
        );
    }

    /** @return list<list<mixed>> */
    private function righeExcel(string $contenuto, string $estensione): array
    {
        $file = tempnam(sys_get_temp_dir(), 'pfb').'.'.$estensione;
        file_put_contents($file, $contenuto);

        try {
            return Excel::toArray(new class implements ToArray
            {
                public function array(array $array): void {}
            }, $file)[0] ?? [];
        } finally {
            @unlink($file);
        }
    }

    /**
     * @param  list<list<mixed>>  $tabella
     * @return list<RigaProformaBanca>
     */
    private function righe(array $tabella): array
    {
        $mappa = [];
        $indice = null;

        foreach ($tabella as $i => $riga) {
            $candidata = $this->mappaColonne($riga);

            if (isset($candidata['importo']) && count($candidata) >= 2) {
                $mappa = $candidata;
                $indice = $i;
                break;
            }
        }

        if ($indice === null) {
            throw new RuntimeException('Intestazioni non riconosciute: servono almeno una colonna con la pratica (o il cliente) e una con la provvigione.');
        }

        $righe = [];

        foreach (array_slice($tabella, $indice + 1) as $riga) {
            $dati = array_map(fn (int $colonna): mixed => $riga[$colonna] ?? null, $mappa);

            if (collect($dati)->contains(fn (mixed $valore): bool => is_string($valore) && preg_match('/^\s*(sub)?totale\b/i', $valore))) {
                continue;
            }

            if (blank($dati['cognome'] ?? null) && filled($dati['nominativo'] ?? null)) {
                [$dati['cognome'], $dati['nome']] = array_pad(explode(' ', trim((string) $dati['nominativo']), 2), 2, null);
            }

            if (($riga = RigaProformaBanca::da($dati)) !== null) {
                $righe[] = $riga;
            }
        }

        return $righe;
    }

    /**
     * @param  list<mixed>  $intestazioni
     * @return array<string, int>
     */
    private function mappaColonne(array $intestazioni): array
    {
        $mappa = [];

        foreach ($intestazioni as $colonna => $testo) {
            $chiave = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii((string) $testo)));

            foreach (self::INTESTAZIONI as $campo => $sinonimi) {
                if (! isset($mappa[$campo]) && in_array($chiave, $sinonimi, true)) {
                    $mappa[$campo] = (int) $colonna;
                    break;
                }
            }
        }

        return $mappa;
    }

    /**
     * Immagini e PDF: l'AI restituisce le righe del dettaglio.
     *
     * @return list<RigaProformaBanca>
     */
    protected function leggiConAi(string $contenuto, string $mime): array
    {
        $blocco = str_starts_with($mime, 'image/')
            ? new ImageContent(base64_encode($contenuto), SourceType::BASE64, $mime)
            : new FileContent(base64_encode($contenuto), SourceType::BASE64, $mime ?: 'application/pdf');

        $elenco = Agent::make()
            ->setAiProvider($this->provider())
            ->setInstructions((string) new SystemPrompt(
                background: ['Leggi il dettaglio delle pratiche e delle provvigioni inviato da una banca a un mediatore creditizio.'],
                steps: ['Riporta una riga per ogni pratica con la provvigione riconosciuta. Salta intestazioni, totali e righe senza importo. Nelle foto distingui con cura cifre e lettere simili (5 e S, 0 e O, 1 e I): se il codice pratica è fatto di sole cifre in tutte le righe, riportalo con sole cifre.'],
                output: ['Importi come numeri con il punto decimale. Se un dato non c\'è, lascialo vuoto: non inventare nulla.'],
            ))
            ->structured(new UserMessage([new TextContent('Estrai le righe del dettaglio.'), $blocco]), ElencoProformaBanca::class);

        return array_values(array_filter(array_map(
            fn (object $riga): ?RigaProformaBanca => RigaProformaBanca::da([
                'codice_pratica' => $riga->codice_pratica,
                'codice_fiscale' => $riga->codice_fiscale,
                'cognome' => $riga->cognome,
                'nome' => $riga->nome,
                'importo' => $riga->importo,
            ]),
            $elenco->righe ?? [],
        )));
    }

    protected function provider(): AIProviderInterface
    {
        $key = env('ANTHROPIC_API_KEY');

        if (blank($key)) {
            throw new RuntimeException('Chiave API Anthropic mancante: imposta ANTHROPIC_API_KEY nel file .env');
        }

        return new Anthropic(key: (string) $key, model: (string) env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'), max_tokens: 8192);
    }
}
