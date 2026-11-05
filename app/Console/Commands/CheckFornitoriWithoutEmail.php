<?php

namespace App\Console\Commands;

use App\Enums\Severity;
use App\Models\Fornitore;
use App\Services\CheckStatus;

class CheckFornitoriWithoutEmail extends StatusCheckCommand
{
    protected $signature = 'fornitori:check-missing-email';

    protected $description = 'Stato del controllo sui fornitori attivi (non dipendenti, Enasarco diverso da «no») senza email, a cui non si possono inviare i proforma';

    public function checkStatus(): CheckStatus
    {
        $fornitori = Fornitore::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('isdipendente', false)->orWhereNull('isdipendente'))
            ->where(fn ($query) => $query->whereNull('enasarco')->orWhere('enasarco', '!=', 'no'))
            ->where(fn ($query) => $query->whereNull('email')->orWhere('email', ''))
            ->orderBy('name')
            ->get(['name', 'piva', 'enasarco']);

        $lines = $fornitori->map(fn (Fornitore $fornitore): string => sprintf(
            '- %s (P.IVA %s, Enasarco: %s)',
            $fornitore->name,
            $fornitore->piva ?: 'assente',
            $fornitore->enasarco ?: 'non indicato',
        ));

        return new CheckStatus(
            $fornitori->count(),
            Severity::fromCount($fornitori->count(), regularFrom: 1, warningFrom: 5, alertFrom: 20),
            $lines->isEmpty() ? null : $lines->implode("\n"),
        );
    }
}
