<?php

namespace App\Services\ProformaBanca;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;

/** Risposta strutturata dell'AI: le righe del dettaglio della banca. */
class ElencoProformaBanca
{
    /** @var RigaLettaDallAi[] */
    #[SchemaProperty(description: 'Una riga per ogni pratica del dettaglio', required: true, anyOf: [RigaLettaDallAi::class])]
    #[ArrayOf(RigaLettaDallAi::class)]
    public array $righe = [];
}
