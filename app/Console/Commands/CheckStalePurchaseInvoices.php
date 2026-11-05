<?php

namespace App\Console\Commands;

use App\Enums\Severity;
use App\Models\PurchaseInvoice;
use App\Services\CheckStatus;
use Illuminate\Support\Carbon;

class CheckStalePurchaseInvoices extends StatusCheckCommand
{
    protected $signature = 'purchase-invoices:check-stale';

    protected $description = "Stato del controllo sull'età dell'ultima fattura di acquisto, cioè la data di registrazione più recente (il valore è in giorni): regular da 25 giorni, warning da 31, alert da 45";

    public function checkStatus(): CheckStatus
    {
        $latest = PurchaseInvoice::query()->max('registration_date');

        if (! $latest) {
            return new CheckStatus('nessuna fattura', Severity::Alert);
        }

        $days = (int) Carbon::parse($latest)->startOfDay()->diffInDays(now()->startOfDay());

        return new CheckStatus(
            $days,
            self::severityFor($days),
            "L'ultima fattura di acquisto è del ".Carbon::parse($latest)->format('d/m/Y')." ({$days} giorni fa).",
        );
    }

    public static function severityFor(int $days): Severity
    {
        return Severity::fromCount($days, regularFrom: 25, warningFrom: 31, alertFrom: 45);
    }
}
