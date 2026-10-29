<?php

namespace App\Services\ProformaBanca;

use App\Models\Proforma;
use Unico\Core\Models\Document;

/** Esito dell'importazione del dettaglio di una banca. */
final readonly class EsitoImportProformaBanca
{
    /**
     * @param  list<string>  $difformita  vuoto se tutto torna
     */
    public function __construct(
        public Document $documento,
        public string $banca,
        public int $righe,
        public int $abbinate,
        public array $difformita,
        public ?Proforma $proforma,
    ) {}

    public function ok(): bool
    {
        return $this->difformita === [];
    }

    /**
     * Le sole anomalie, senza le righe: per ogni tipo quante volte ricorre (il dettaglio resta nel documento).
     *
     * @return array<string, int>
     */
    public function anomalie(): array
    {
        $tipi = [];

        foreach ($this->difformita as $difformita) {
            $motivo = explode(': ', $difformita, 2)[1] ?? $difformita;
            $tipo = match (true) {
                str_starts_with($motivo, 'nessuna provvigione attiva') => 'Nessuna provvigione attiva trovata',
                str_contains($motivo, 'di un\'altra banca') => 'Provvigione attiva di un\'altra banca',
                str_starts_with($motivo, 'la banca riconosce') => 'Importo della banca diverso dalla provvigione attiva',
                str_contains($motivo, 'già registrato') => 'Storno già registrato',
                default => $motivo,
            };
            $tipi[$tipo] = ($tipi[$tipo] ?? 0) + 1;
        }

        return $tipi;
    }
}
