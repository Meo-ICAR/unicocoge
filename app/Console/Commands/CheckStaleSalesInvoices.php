<?php

namespace App\Console\Commands;

use App\Enums\Severity;
use App\Models\SalesInvoice;
use App\Services\CheckStatus;
use Illuminate\Support\Carbon;

class CheckStaleSalesInvoices extends StatusCheckCommand
{
    protected $signature = 'sales-invoices:check-stale';

    protected $description = "Stato del controllo sull'età dell'ultima fattura di vendita, cioè la data di registrazione più recente (il valore è in giorni)";

    public function checkStatus(): CheckStatus
    {
        $latest = SalesInvoice::query()->max('registration_date');

        if (! $latest) {
            return new CheckStatus('nessuna fattura', Severity::Alert);
        }

        $days = (int) Carbon::parse($latest)->startOfDay()->diffInDays(now()->startOfDay());

        return new CheckStatus(
            $days,
            Severity::fromCount($days, regularFrom: 15, warningFrom: 30, alertFrom: 45),
            "L'ultima fattura di vendita è del ".Carbon::parse($latest)->format('d/m/Y')." ({$days} giorni fa).",
        );
    }
}
