<?php

namespace App\Services;

use App\Models\Proforma;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProformaInvoiceMatchingService
{
    /**
     * Abbina i proforma alle fatture di vendita.
     *
     * Regole di abbinabilità (Proforma::abbinabiliAFatturaVendita): il proforma è di un istituto (cliente) con la stessa partita IVA della
     * fattura (anche se il cliente è fittizio, `is_dummy`: le provvigioni fanno capo a lui), e la fattura non è precedente al proforma (data di registrazione >= data di invio).
     * Tra i proforma abbinabili si collega in automatico quello con lo stesso importo (compenso + anticipo + contributo, tolleranza di 10 euro).
     * Una fattura è candidata se non ha ancora un proforma: lo stato `closed` non conta.
     *
     * @return array Statistics about the matching process
     */
    public function matchProformasToInvoices(): array
    {
        $stats = [
            'processed_invoices' => 0,
            'matched_proformas' => 0,
            'errors' => [],
        ];

        $salesInvoices = SalesInvoice::query()
            ->libere()
            ->whereNotNull('vat_number')
            ->where('vat_number', '!=', '')
            ->whereNotNull('amount')
            ->whereNotNull('registration_date')
            ->orderBy('registration_date')
            ->get();

        Log::info("Processing {$salesInvoices->count()} sales invoices without a proforma");

        foreach ($salesInvoices as $salesInvoice) {
            try {
                $this->processSalesInvoice($salesInvoice, $stats);
                $stats['processed_invoices']++;
            } catch (\Exception $e) {
                Log::error("Error processing sales invoice {$salesInvoice->id}: ".$e->getMessage());
                $stats['errors'][] = "Sales Invoice {$salesInvoice->id}: ".$e->getMessage();
            }
        }

        Log::info("Matching completed. Processed: {$stats['processed_invoices']}, Matched: {$stats['matched_proformas']}");

        return $stats;
    }

    /**
     * Process a single sales invoice and try to match with proformas
     */
    private function processSalesInvoice(SalesInvoice $salesInvoice, array &$stats): void
    {
        $proformas = Proforma::query()
            ->whereNull('invoiceable_id')
            ->abbinabiliAFatturaVendita($salesInvoice)
            ->orderBy('sended_at')
            ->get();

        if ($proformas->isEmpty()) {
            Log::debug("No proformas found for sales invoice {$salesInvoice->id}");

            return;
        }

        foreach ($proformas as $proforma) {
            // Calculate total amount (compenso + anticipo + contributo)
            $proformaTotal = $proforma->compenso + $proforma->anticipo + $proforma->contributo;

            if ($this->amountsMatch($salesInvoice->amount, $proformaTotal)) {
                $this->associateProformaWithInvoice($proforma, $salesInvoice);
                $stats['matched_proformas']++;

                Log::info("Matched proforma {$proforma->id} with sales invoice {$salesInvoice->id} - Amount: {$salesInvoice->amount}");

                // La fattura ha ora il suo proforma: gli altri con lo stesso importo restano da riconciliare a mano.
                return;
            }
        }
    }

    /**
     * Check if two amounts match (allowing small floating point differences)
     */
    private function amountsMatch(float $amount1, float $amount2, float $tolerance = 10): bool
    {
        return abs($amount1 - $amount2) <= $tolerance;
    }

    /**
     * Associate a proforma with a sales invoice
     */
    private function associateProformaWithInvoice(Proforma $proforma, SalesInvoice $salesInvoice): void
    {
        DB::transaction(function () use ($proforma, $salesInvoice) {
            $proforma->update([
                'invoiceable_type' => SalesInvoice::class,
                'invoiceable_id' => $salesInvoice->id,
                'stato' => 'Pagato',
            ]);

            // Fattura emessa = pagato, anche per le provvigioni di dettaglio che compongono il proforma.
            $proforma->provvigioni()->update(['stato' => 'Pagato']);
            $salesInvoice->update([
                'closed' => 1,
            ]);
        });
    }

    /**
     * Get statistics about unmatched proformas
     */
    public function getUnmatchedStatistics(): array
    {
        $unmatchedProformas = Proforma::whereNull('invoiceable_id')
            ->where('tipo', 'Istituto')
            ->whereNotNull('sended_at')
            ->count();

        $unmatchedInvoices = SalesInvoice::libere()
            ->whereNotNull('amount')
            ->count();

        return [
            'unmatched_proformas' => $unmatchedProformas,
            'unmatched_invoices' => $unmatchedInvoices,
        ];
    }
}
