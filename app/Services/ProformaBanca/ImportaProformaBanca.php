<?php

namespace App\Services\ProformaBanca;

use App\Models\Clienti;
use App\Models\Pratica;
use App\Models\Proforma;
use App\Models\Provvigione;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Unico\Core\Documents\Listeners\AnalyzeUploadedDocument;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\MorphMap;

/**
 * Importa il dettaglio delle pratiche inviato da una banca: lo abbina alle provvigioni attive, lo archivia tra i documenti della banca,
 * aggiorna il codice pratica banca e ne confronta gli importi. Il proforma si crea solo se non ci sono difformità.
 */
class ImportaProformaBanca
{
    public const TIPO_DOCUMENTO = 'PROFORMA_BANCA';

    public function __construct(private LettoreProformaBanca $lettore) {}

    public function importa(string $contenuto, string $nomeFile, string $mime, string $dataEmissione): EsitoImportProformaBanca
    {
        $righe = $this->lettore->leggi($contenuto, $nomeFile, $mime);

        if ($righe === []) {
            throw new RuntimeException('Nessuna riga con una provvigione letta dal file.');
        }

        $abbinamenti = $this->abbina($righe);
        $banca = $this->bancaPrevalente($abbinamenti);
        $cliente = Clienti::query()->where('name', $banca)->first()
            ?? throw new RuntimeException("Banca «{$banca}» non trovata tra i clienti.");

        $difformita = [];
        $daAssociare = collect();
        $stornoDaRegistrare = [];
        $codiciDaAggiornare = [];

        foreach ($abbinamenti as $numero => $abbinamento) {
            $descrizione = $this->descrivi($numero + 1, $abbinamento['riga']);
            $provvigioni = $abbinamento['provvigioni'];

            if ($provvigioni->isEmpty() && $abbinamento['originale'] !== null && $abbinamento['originale']->istituto_finanziario === $banca) {
                if ($this->stornoEsiste($abbinamento['originale'])) {
                    $difformita[] = "{$descrizione}: lo storno della provvigione {$abbinamento['originale']->id} è già registrato e non è più tra le attive.";
                } else {
                    $stornoDaRegistrare[] = [$abbinamento['originale'], $abbinamento['riga']];
                    $codiciDaAggiornare[] = [$abbinamento['originale']->id_pratica, $abbinamento['riga']];
                }

                continue;
            }

            if ($provvigioni->isEmpty()) {
                $difformita[] = "{$descrizione}: nessuna provvigione attiva trovata.";

                continue;
            }

            $altraBanca = $provvigioni->first(fn (Provvigione $p): bool => $p->istituto_finanziario !== $banca);

            if ($altraBanca !== null) {
                $difformita[] = "{$descrizione}: la provvigione attiva è di un'altra banca ({$altraBanca->istituto_finanziario}).";

                continue;
            }

            $codiciDaAggiornare[] = [$provvigioni->first()->id_pratica, $abbinamento['riga']];

            $attesa = $provvigioni->sum(fn (Provvigione $p): float => (float) $p->importo);

            if ($this->centesimi($attesa) !== $this->centesimi($abbinamento['riga']->importo)) {
                $difformita[] = sprintf('%s: la banca riconosce € %s, la provvigione attiva è € %s.', $descrizione, $this->euro($abbinamento['riga']->importo), $this->euro($attesa));

                continue;
            }

            $daAssociare = $daAssociare->concat($provvigioni);
        }

        $abbinate = $daAssociare->count() + count($stornoDaRegistrare);
        $documento = $this->archivia($cliente, $contenuto, $nomeFile, $this->note($righe, $abbinate, $difformita, $dataEmissione), $difformita);
        $proforma = null;

        $storni = collect($stornoDaRegistrare)->map(fn (array $s): Provvigione => $this->registraStorno(...$s));

        foreach ($codiciDaAggiornare as [$codicePratica, $riga]) {
            $this->aggiornaCodiceBanca($codicePratica, $riga);
        }

        // Con difformità il proforma non si crea e nessuna provvigione si associa: prima vanno rimosse, poi si ricarica il file.
        if ($difformita === []) {
            $proforma = Proforma::associaProvvigioni($daAssociare->concat($storni), $dataEmissione);
        }

        return new EsitoImportProformaBanca($documento, $banca, count($righe), $abbinate, $difformita, $proforma);
    }

    /**
     * Per ogni riga le provvigioni attive («Inserito», in entrata) della pratica, ognuna usata una volta sola.
     * Si cerca per codice pratica (della banca o nostro), poi per codice fiscale, infine per cognome e importo.
     *
     * @param  list<RigaProformaBanca>  $righe
     * @return list<array{riga: RigaProformaBanca, provvigioni: Collection<int, Provvigione>, originale: ?Provvigione}>
     */
    private function abbina(array $righe): array
    {
        $usate = [];
        $risultato = [];

        foreach ($righe as $riga) {
            $storno = $riga->importo < 0;
            $candidate = $this->candidate($riga)
                ->reject(fn (Provvigione $p): bool => isset($usate[$p->id]) || ($storno && (float) $p->importo >= 0))
                ->values();
            $esatte = $candidate->filter(fn (Provvigione $p): bool => $this->centesimi((float) $p->importo) === $this->centesimi($riga->importo));
            $scelte = $esatte->isNotEmpty() ? collect([$esatte->first()]) : $candidate;

            foreach ($scelte as $provvigione) {
                $usate[$provvigione->id] = true;
            }

            $risultato[] = [
                'riga' => $riga,
                'provvigioni' => $scelte,
                'originale' => $storno && $scelte->isEmpty() ? $this->originaleDelloStorno($riga) : null,
            ];
        }

        return $risultato;
    }

    /** @return Collection<int, Provvigione> */
    private function candidate(RigaProformaBanca $riga): Collection
    {
        $attive = fn () => Provvigione::query()->where('entrata_uscita', 'Entrata')->where('stato', 'Inserito')->whereNot('importo', 0);

        if (filled($riga->codicePratica)) {
            $varianti = $this->varianti($riga->codicePratica);
            $codici = Pratica::query()
                ->where(fn ($q) => $q->whereIn('codice_pratica', $varianti)->orWhereIn('codice_pratica_banca', $varianti))
                ->pluck('codice_pratica');
            $trovate = $attive()->whereIn('id_pratica', $codici->merge($varianti)->unique())->get();

            if ($trovate->isNotEmpty()) {
                return $trovate;
            }
        }

        if (filled($riga->codiceFiscale)) {
            $codici = Pratica::query()->where('codice_fiscale', $riga->codiceFiscale)->pluck('codice_pratica');
            $trovate = $attive()->where(fn ($q) => $q->where('cf', $riga->codiceFiscale)->orWhereIn('id_pratica', $codici))->get();

            if ($trovate->isNotEmpty()) {
                return $trovate;
            }
        }

        $codici = $this->codiciPerNome($riga);

        if ($codici->isNotEmpty()) {
            return $attive()->whereIn('id_pratica', $codici)->get();
        }

        return collect();
    }

    /**
     * Codici delle pratiche intestate al cliente della riga, a prescindere dall'ordine di nome e cognome.
     *
     * @return Collection<int, string>
     */
    private function codiciPerNome(RigaProformaBanca $riga): Collection
    {
        $parole = $this->parole(trim(($riga->cognome ?? '').' '.($riga->nome ?? '')));

        if ($parole === []) {
            return collect();
        }

        $piuLunga = collect($parole)->sortByDesc(fn (string $p): int => mb_strlen($p))->first();

        return Pratica::query()
            ->where(fn ($q) => $q->whereRaw('LOWER(cognome_cliente) LIKE ?', ["%{$piuLunga}%"])->orWhereRaw('LOWER(nome_cliente) LIKE ?', ["%{$piuLunga}%"]))
            ->get(['codice_pratica', 'cognome_cliente', 'nome_cliente'])
            ->filter(function (Pratica $pratica) use ($parole): bool {
                $della = $this->parole($pratica->cognome_cliente.' '.$pratica->nome_cliente);

                return $della !== [] && array_diff($della, $parole) === [] && array_diff($parole, $della) === [];
            })
            ->pluck('codice_pratica');
    }

    /** Lo storno chiede la restituzione di una provvigione già incassata: la sua originale, positiva e dello stesso importo. */
    private function originaleDelloStorno(RigaProformaBanca $riga): ?Provvigione
    {
        return Provvigione::query()
            ->where('entrata_uscita', 'Entrata')
            ->where('importo', '>', 0)
            ->whereIn('id_pratica', $this->codiciPerNome($riga)->push((string) $riga->codicePratica))
            ->whereRaw('ABS(importo - ?) < 0.005', [abs($riga->importo)])
            ->orderByRaw("stato = 'Pagato' desc")
            ->first();
    }

    /** Lo storno di una provvigione esiste già, anche se non è più attivo: non si duplica. */
    private function stornoEsiste(Provvigione $originale): bool
    {
        return Provvigione::withTrashed()->whereKey($originale->id.'-')->exists();
    }

    /**
     * Aggiunge lo storno tra le provvigioni attive come fa l'import: stessa pratica e istituto dell'originale, id dell'originale con «-»,
     * importo negativo, «Inserito».
     */
    private function registraStorno(Provvigione $originale, RigaProformaBanca $riga): Provvigione
    {
        $id = $originale->id.'-';

        $storno = $originale->replicate();
        $storno->forceFill([
            'id' => $id,
            'importo' => -abs($riga->importo),
            'importo_effettivo' => $originale->importo,
            'descrizione' => 'Storno provvigione',
            'stato' => 'Inserito',
            'status_compenso' => 'Pratica stornata',
            'data_status' => now()->toDateString(),
            'data_inserimento_compenso' => now()->toDateString(),
            'proforma_id' => null,
            'piva' => null,
            'data_pagamento' => null,
            'data_fattura' => null,
            'n_fattura' => null,
            'invoice_number' => null,
        ])->save();

        return $storno;
    }

    /**
     * Le parole di un nominativo, senza maiuscole né ordine: nelle pratiche nome e cognome sono spesso invertiti e le righe di storno
     * portano la parola «storno».
     *
     * @return list<string>
     */
    private function parole(string $nominativo): array
    {
        $parole = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($nominativo), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_diff($parole, ['storno'])));
    }

    /**
     * Il codice com'è scritto e, per le foto, con le lettere che l'OCR scambia per cifre (S per 5, O per 0, I per 1) riportate a cifre.
     *
     * @return list<string>
     */
    private function varianti(string $codice): array
    {
        $numerico = strtr($codice, ['S' => '5', 's' => '5', 'O' => '0', 'o' => '0', 'I' => '1', 'l' => '1']);

        return array_values(array_unique([$codice, $numerico]));
    }

    /** @param  list<array{riga: RigaProformaBanca, provvigioni: Collection<int, Provvigione>, originale: ?Provvigione}>  $abbinamenti */
    private function bancaPrevalente(array $abbinamenti): string
    {
        $banca = collect($abbinamenti)
            ->flatMap(fn (array $a) => $a['provvigioni']->concat(array_filter([$a['originale']])))
            ->pluck('istituto_finanziario')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        return $banca ?? throw new RuntimeException('Impossibile dedurre la banca: nessuna riga del file corrisponde a una provvigione attiva.');
    }

    private function aggiornaCodiceBanca(?string $codicePratica, RigaProformaBanca $riga): void
    {
        $codice = $riga->codicePratica;

        if (blank($codice) || blank($codicePratica)) {
            return;
        }

        $pratica = Pratica::query()->where('codice_pratica', $codicePratica)->first();

        if ($pratica !== null && $pratica->codice_pratica !== $codice && $pratica->codice_pratica_banca !== $codice) {
            $pratica->update(['codice_pratica_banca' => $codice]);
        }
    }

    /** @param  list<string>  $difformita  con difformità il documento è «Anomalo» e la nota di motivazione le elenca (la colonna Stato la mostra) */
    private function archivia(Clienti $cliente, string $contenuto, string $nomeFile, string $note, array $difformita): Document
    {
        $documento = Document::query()->create([
            'documentable_type' => MorphMap::aliasOf($cliente),
            'documentable_id' => (string) $cliente->getKey(),
            'document_type_id' => DocumentType::query()->where('code', self::TIPO_DOCUMENTO)->value('id'),
            'name' => $nomeFile,
            'channel' => 'upload',
            'uploaded_by' => Auth::id(),
            'emitted_at' => now(),
            'internal_notes' => $note,
            'status' => $difformita === [] ? DocumentStatus::UPLOADED : DocumentStatus::ANOMALY,
            'rejection_note' => $difformita === [] ? null : implode("\n", $difformita),
        ]);

        AnalyzeUploadedDocument::suspendedWhile(fn () => $documento->addMediaFromString($contenuto)
            ->usingFileName($nomeFile)
            ->toMediaCollection(Document::COLLECTION));

        return $documento;
    }

    /**
     * @param  list<RigaProformaBanca>  $righe
     * @param  Collection<int, Provvigione>  $abbinate
     * @param  list<string>  $difformita
     */
    private function note(array $righe, int $abbinate, array $difformita, string $dataEmissione): string
    {
        $testa = sprintf('Dettaglio banca importato il %s: %d righe, %d provvigioni abbinate.', now()->format('d/m/Y H:i'), count($righe), $abbinate);

        return $difformita === []
            ? $testa."\nNessuna difformità: proforma del ".Carbon::parse($dataEmissione)->format('d/m/Y').' creato.'
            : $testa."\nDifformità (".count($difformita).'): proforma non creato e provvigioni non associate (i codici pratica banca sono aggiornati). Rimuovere le difformità e ricaricare il file:'."\n- ".implode("\n- ", $difformita);
    }

    private function descrivi(int $numero, RigaProformaBanca $riga): string
    {
        $cliente = trim(($riga->cognome ?? '').' '.($riga->nome ?? ''));

        return trim("Riga {$numero} ".($riga->codicePratica ?? '').($cliente !== '' ? " ({$cliente})" : ''));
    }

    private function centesimi(float $importo): int
    {
        return (int) round($importo * 100);
    }

    private function euro(float $importo): string
    {
        return number_format($importo, 2, ',', '.');
    }
}
