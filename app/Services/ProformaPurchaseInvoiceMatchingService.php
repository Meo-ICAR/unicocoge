<?php

namespace App\Services;

use App\Models\Proforma;
use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProformaPurchaseInvoiceMatchingService
{
    /**
     * Abbina i proforma alle fatture di acquisto.
     *
     * Regole di abbinabilità (Proforma::abbinabiliAFatturaAcquisto): il proforma è di un fornitore con la stessa partita IVA della fattura
     * (PurchaseInvoice.vat_number = Fornitore.piva) e la fattura non è precedente al proforma (data di registrazione >= data di invio).
     * Tra i proforma abbinabili si sceglie quello con lo stesso importo (tolleranza di 5 euro): l'importo non rende abbinabile un proforma, ma
     * serve a collegare in automatico quello giusto; gli altri si riconciliano a mano dalla fattura.
     *
     * Una fattura è candidata se non ha ancora un proforma: lo stato `closed` (pagata in contabilità) non conta.
     *
     * @return array{processed_invoices: int, matched_proformas: int, errors: string[]}
     */
    public function matchProformasToInvoices(): array
    {
        $stats = [
            'processed_invoices' => 0,
            'matched_proformas'  => 0,
            'errors'             => [],
        ];

        $purchaseInvoices = PurchaseInvoice::query()
            ->libere()
            ->where('is_nopractice', false)
            ->whereNotNull('vat_number')
            ->where('vat_number', '!=', '')
            ->whereNotNull('amount')
            ->whereNotNull('registration_date')
            ->orderBy('registration_date')
            ->get();

        Log::info("[PurchaseMatching] Fatture da elaborare: {$purchaseInvoices->count()}");

        foreach ($purchaseInvoices as $purchaseInvoice) {
            try {
                $this->processPurchaseInvoice($purchaseInvoice, $stats);
                $stats['processed_invoices']++;
            } catch (\Throwable $e) {
                Log::error("[PurchaseMatching] Errore su fattura {$purchaseInvoice->id}: " . $e->getMessage());
                $stats['errors'][] = "PurchaseInvoice {$purchaseInvoice->id}: " . $e->getMessage();
            }
        }

        Log::info("[PurchaseMatching] Completato. Elaborate: {$stats['processed_invoices']}, Abbinate: {$stats['matched_proformas']}");

        return $stats;
    }

    private function processPurchaseInvoice(PurchaseInvoice $purchaseInvoice, array &$stats): void
    {
        $proformas = Proforma::query()
            ->whereNull('invoiceable_id')
            ->abbinabiliAFatturaAcquisto($purchaseInvoice)
            ->orderBy('sended_at')
            ->get();

        if ($proformas->isEmpty()) {
            Log::debug("[PurchaseMatching] Nessun proforma abbinabile per la fattura {$purchaseInvoice->id} (P.IVA: {$purchaseInvoice->vat_number})");

            return;
        }

        $this->tryMatchByAmount($proformas, $purchaseInvoice, $stats);
    }

    /**
     * Scorre le proformas candidate e abbina la prima il cui totale combacia con l'importo fattura.
     * Restituisce true se ha trovato e associato un match.
     */
    private function tryMatchByAmount(\Illuminate\Support\Collection $proformas, PurchaseInvoice $purchaseInvoice, array &$stats): bool
    {
        $invoiceAmount = (float) $purchaseInvoice->amount;

        foreach ($proformas as $proforma) {
            if ($this->amountsMatch($invoiceAmount, (float) $proforma->totale)) {
                $this->associateProformaWithInvoice($proforma, $purchaseInvoice);
                $stats['matched_proformas']++;
                Log::info("[PurchaseMatching] Proforma {$proforma->id} ↔ PurchaseInvoice {$purchaseInvoice->id} — importo: {$invoiceAmount}");
                return true;
            }
        }

        return false;
    }

    /**
     * Confronta due importi con una tolleranza per differenze floating-point.
     */
    private function amountsMatch(float $amount1, float $amount2, float $tolerance = 5): bool
    {
        return abs($amount1 - $amount2) <= $tolerance;
    }

    /**
     * Associa la proforma alla fattura in una transazione atomica.
     *
     * - Imposta invoiceable_type / invoiceable_id sulla proforma
     * - Marca le provvigioni collegate come 'Pagato'
     * - Chiude la PurchaseInvoice
     */
    private function associateProformaWithInvoice(Proforma $proforma, PurchaseInvoice $purchaseInvoice): void
    {
        DB::transaction(function () use ($proforma, $purchaseInvoice) {
            $proforma->update([
                'invoiceable_type' => PurchaseInvoice::class,
                'invoiceable_id'   => $purchaseInvoice->id,
            ]);

            $proforma->provvigioni()->update([
                'stato' => 'Pagato',
            ]);

            $purchaseInvoice->update([
                'closed' => true,
            ]);
        });
    }

    /**
     * Restituisce statistiche sui record non ancora abbinati.
     *
     * @return array{unmatched_proformas: int, unmatched_invoices: int}
     */
    public function getUnmatchedStatistics(): array
    {
        $unmatchedProformas = Proforma::whereNull('invoiceable_id')
            ->where('tipo', 'Agente')
            ->whereNotNull('sended_at')
            ->count();

        $unmatchedInvoices = PurchaseInvoice::libere()
            ->whereNotNull('vat_number')
            ->whereNotNull('amount')
            ->count();

        return [
            'unmatched_proformas' => $unmatchedProformas,
            'unmatched_invoices'  => $unmatchedInvoices,
        ];
    }
}
