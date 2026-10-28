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
}
