<?php

namespace App\Services\ProformaBanca;

use NeuronAI\StructuredOutput\SchemaProperty;

/** Riga del dettaglio come la restituisce l'AI. */
class RigaLettaDallAi
{
    #[SchemaProperty(description: 'Codice o numero della pratica, come scritto dalla banca', required: false)]
    public ?string $codice_pratica = null;

    #[SchemaProperty(description: 'Codice fiscale del cliente', required: false)]
    public ?string $codice_fiscale = null;

    #[SchemaProperty(description: 'Cognome del cliente', required: false)]
    public ?string $cognome = null;

    #[SchemaProperty(description: 'Nome del cliente', required: false)]
    public ?string $nome = null;

    #[SchemaProperty(description: 'Provvigione riconosciuta in euro, numero con il punto decimale', required: true)]
    public float $importo = 0.0;
}
