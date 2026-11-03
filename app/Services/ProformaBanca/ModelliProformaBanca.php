<?php

namespace App\Services\ProformaBanca;

use Illuminate\Support\Str;

/**
 * Modelli noti dei dettagli inviati dalle banche. Si prova a riconoscere il modello con una regex sul testo del file (o sulle
 * intestazioni di un foglio Excel) e a leggerne le righe senza l'AI, che resta per i modelli sconosciuti e le immagini.
 * Gli esempi sono in `storage/app/private/proforma`; per un modello nuovo: una voce in MODELLI e un metodo che ne legge le righe.
 */
class ModelliProformaBanca
{
    /** Chiave del modello => regex che lo riconosce nel testo del file. */
    private const MODELLI = [
        'agos' => '/AGOS\s+DUCATO|ELENCO\s+PRATICHE\s+ABBINATE\s+AL\s+PROFORMA/i',
        'capitalfin' => '/CAP\.?\s*ITAL\.?\s*FIN/i',
        'compass' => '/Triangolazione\s*Rinnovo|Cod\.\s*AG\s*Raccolta/i',
        'fucino' => '/FUCINO\s+FINANCE/i',
        'ibl' => '/Totale\s+capitale:.*Totale\s+provvigione:/is',
    ];

    /** Il modello del testo, se lo si conosce. */
    public function riconosci(string $testo): ?string
    {
        foreach (self::MODELLI as $chiave => $regex) {
            if (preg_match($regex, $testo) === 1) {
                return $chiave;
            }
        }

        return null;
    }

    /**
     * Le righe lette con il modello riconosciuto; vuoto se il modello non c'è o non produce righe (allora tocca all'AI).
     *
     * @return list<RigaProformaBanca>
     */
    public function leggiTesto(string $testo): array
    {
        $righe = match ($this->riconosci($testo)) {
            'agos' => $this->agos($testo),
            'capitalfin' => $this->capitalfin($testo),
            'compass' => $this->compass($testo),
            'fucino' => $this->fucino($testo),
            'ibl' => $this->ibl($testo),
            default => [],
        };

        return array_values(array_filter($righe));
    }

    /**
     * Mediobanca: un foglio con «IMPORTO DA FATTURARE» in testa e l'elenco dei finanziamenti, senza la provvigione per riga.
     * Il totale si attribuisce solo se il finanziamento è uno.
     *
     * @param  list<list<mixed>>  $tabella
     * @return list<RigaProformaBanca>
     */
    public function leggiTabella(array $tabella): array
    {
        $totale = null;
        $mappa = null;
        $righe = [];

        foreach ($tabella as $riga) {
            if (str_contains(Str::upper((string) ($riga[0] ?? '')), 'IMPORTO DA FATTURARE')) {
                $totale = $riga[1] ?? null;

                continue;
            }

            if ($mappa === null) {
                $chiavi = array_map(fn (mixed $cella): string => preg_replace('/[^a-z]/', '', Str::lower(Str::ascii((string) $cella))) ?? '', $riga);
                $mappa = in_array('nrfinanziamento', $chiavi, true) && in_array('originator', $chiavi, true) ? array_flip($chiavi) : null;

                continue;
            }

            if (filled($riga[$mappa['nrfinanziamento']] ?? null)) {
                $righe[] = $riga;
            }
        }

        if ($mappa === null || count($righe) !== 1 || $totale === null) {
            return [];
        }

        $nominativo = collect($mappa)->first(fn (int $colonna, string $chiave): bool => str_starts_with($chiave, 'nomecognome'));
        $codiceFiscale = collect($mappa)->first(fn (int $colonna, string $chiave): bool => str_starts_with($chiave, 'codicefiscale'));
        $dati = $righe[0];

        return array_values(array_filter([RigaProformaBanca::da([
            'codice_pratica' => (string) $dati[$mappa['nrfinanziamento']],
            'codice_fiscale' => $codiceFiscale === null ? null : ($dati[$codiceFiscale] ?? null),
            'cognome' => $nominativo === null ? null : ($dati[$nominativo] ?? null),
            'importo' => $totale,
        ])]));
    }

    /** @return list<?RigaProformaBanca> «79302092 07/09/2026 RACANO ROBERTO    4,22    5.000,00      211,00        0,00» */
    private function agos(string $testo): array
    {
        preg_match_all('/^\s*(\d{6,10})\s+\d{2}\/\d{2}\/\d{4}\s+(.+?)\s+\d+,\d{2}\s+[\d.]+,\d{2}\s+(-?[\d.]+,\d{2})\s+[\d.]+,\d{2}\s*$/m', $testo, $m, PREG_SET_ORDER);

        return array_map(fn (array $r): ?RigaProformaBanca => RigaProformaBanca::da(['codice_pratica' => $r[1], 'cognome' => $r[2], 'importo' => $r[3]]), $m);
    }

    /** @return list<?RigaProformaBanca> «5053880 STORNO BUZZERIO ARMANDO -1.916,64 €» */
    private function capitalfin(string $testo): array
    {
        preg_match_all('/^\s*(\d{6,8})\s+(.+?)\s+(-?[\d.]+,\d{2})\s*€\s*$/mu', $testo, $m, PREG_SET_ORDER);

        return array_map(fn (array $r): ?RigaProformaBanca => RigaProformaBanca::da(['codice_pratica' => $r[1], 'cognome' => $r[2], 'importo' => $r[3]]), $m);
    }

    /** @return list<?RigaProformaBanca> «377178 rinnovo 27/08/2026SETTIMI FERDINANDOSTTFDN49T01G478N 10 5M2 23.880,00 12.202,47 716,40» (l'ultimo importo è la provvigione) */
    private function compass(string $testo): array
    {
        preg_match_all('/^\s*(\d{4,8})\s*(?:[A-Za-z]+\s+)?\d{2}\/\d{2}\/\d{4}\s*(.+?)([A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z])\s+\S+\s+\S+\s+[\d.]+,\d{2}\s+[\d.]+,\d{2}\s+(-?[\d.]+,\d{2})/mu', $testo, $m, PREG_SET_ORDER);

        return array_map(fn (array $r): ?RigaProformaBanca => RigaProformaBanca::da(['codice_pratica' => $r[1], 'codice_fiscale' => $r[3], 'cognome' => $r[2], 'importo' => $r[4]]), $m);
    }

    /** @return list<?RigaProformaBanca> «IOSUB ELENA   CLM: 33.360,00 % Provv.:  7,0020039099    2.335,20» (percentuale e codice pratica sono attaccati) */
    private function fucino(string $testo): array
    {
        preg_match_all('/^\s*(.+?)\s+CLM:\s*[\d.]+,\d{2}\s*%\s*Provv\.:\s*\d{1,2},\d{2}(\d{8})\s+(-?[\d.]+,\d{2})\s*$/m', $testo, $m, PREG_SET_ORDER);

        return array_map(fn (array $r): ?RigaProformaBanca => RigaProformaBanca::da(['codice_pratica' => $r[2], 'cognome' => $r[1], 'importo' => $r[3]]), $m);
    }

    /**
     * IBL: l'estrazione del PDF spezza e incolla le celle in modi diversi, quindi si appiattisce il testo e si taglia a ogni «C» o «P»
     * seguita dal numero pratica; la provvigione è l'ultimo importo in euro della riga.
     *
     * @return list<?RigaProformaBanca>
     */
    private function ibl(string $testo): array
    {
        $piatto = preg_replace('/\s+/u', ' ', $testo) ?? $testo;
        $piatto = preg_replace('/\s*Totale capitale:.*$/ui', '', $piatto) ?? $piatto;
        $pezzi = preg_split('/(?<![A-Za-z])(?=[CP]\s?\d{5,7}\s?[A-Z\'])/u', $piatto, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $righe = [];

        foreach ($pezzi as $pezzo) {
            if (preg_match('/^[CP]\s?(\d{5,7})\s?([A-Z\' ]+?)\s*(?:\d|€)/u', $pezzo, $r) !== 1
                || preg_match_all('/€\s*(-?[\d.]+,\d{2})/u', $pezzo, $importi) < 2) {
                continue;
            }

            $righe[] = RigaProformaBanca::da(['codice_pratica' => $r[1], 'cognome' => $r[2], 'importo' => end($importi[1])]);
        }

        return $righe;
    }
}
