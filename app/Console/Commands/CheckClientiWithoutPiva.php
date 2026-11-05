<?php

namespace App\Console\Commands;

use App\Enums\Severity;
use App\Models\Clienti;
use App\Services\CheckStatus;

class CheckClientiWithoutPiva extends StatusCheckCommand
{
    protected $signature = 'clienti:check-missing-piva';

    protected $description = 'Stato del controllo sugli istituti attivi (non fittizi) senza partita IVA';

    public function checkStatus(): CheckStatus
    {
        $istituti = Clienti::activeWithoutPiva()->get(['id', 'name', 'type', 'abi', 'cf', 'codice', 'citta', 'email']);

        $lines = $istituti->map(fn (Clienti $istituto): string => '- '.$istituto->name.' ('.collect([
            "id {$istituto->id}",
            $istituto->type ? "tipo {$istituto->type}" : null,
            $istituto->abi ? "ABI {$istituto->abi}" : null,
            $istituto->codice ? "codice {$istituto->codice}" : null,
            $istituto->cf ? "C.F. {$istituto->cf}" : null,
            $istituto->citta ?: null,
            $istituto->email ?: null,
        ])->filter()->implode(', ').')');

        return new CheckStatus(
            $istituti->count(),
            Severity::fromCount($istituti->count(), regularFrom: 1, warningFrom: 3, alertFrom: 10),
            $lines->isEmpty() ? null : $lines->implode("\n"),
        );
    }
}
