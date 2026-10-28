<?php

namespace App\Services\ProformaBanca;

/** Una riga del dettaglio inviato dalla banca: la pratica e la provvigione che la banca riconosce. */
final readonly class RigaProformaBanca
{
    public function __construct(
        public float $importo,
        public ?string $codicePratica = null,
        public ?string $codiceFiscale = null,
        public ?string $cognome = null,
        public ?string $nome = null,
    ) {}

    /** @param  array<string, mixed>  $dati */
    public static function da(array $dati): ?self
    {
        $importo = LettoreProformaBanca::numero($dati['importo'] ?? null);

        if ($importo === null) {
            return null;
        }

        $testo = fn (string $chiave): ?string => filled($dati[$chiave] ?? null) ? trim((string) $dati[$chiave]) : null;

        $riga = new self(
            $importo,
            $testo('codice_pratica'),
            ($cf = $testo('codice_fiscale')) === null ? null : strtoupper($cf),
            $testo('cognome'),
            $testo('nome'),
        );

        return $riga->codicePratica === null && $riga->codiceFiscale === null && $riga->cognome === null ? null : $riga;
    }
}
