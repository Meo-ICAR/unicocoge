<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Company;
use App\Models\Fornitore;
use App\Models\PurchaseInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class PurchaseInvoiceImportService
{
    protected $companyId;

    protected $filename;

    protected $processedInvoices = [];

    protected $importResults = [
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'details' => [],
    ];

    public function setCompanyId($companyId): void
    {
        $this->companyId = $companyId;
    }

    public function __construct($filename = null)
    {
        $this->filename = $filename;
    }

    public function import($filePath, $companyId)
    {
        $this->companyId = $companyId;

        // Extract filename from path if not provided
        if (! $this->filename) {
            $this->filename = basename($filePath);
        }

        $this->importResults = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => [],
            'filename' => $this->filename,
        ];

        // Reset processed invoices for new import
        $this->processedInvoices = [];

        // Handle storage path
        $actualFilePath = $filePath;
        if (! file_exists($filePath)) {
            // Try storage path if public path doesn't exist
            $storagePath = str_replace('public/purchase-invoice-imports/', 'storage/app/private/purchase-invoice-imports/', $filePath);
            if (file_exists($storagePath)) {
                $actualFilePath = $storagePath;
                Log::info('File found in storage path', ['path' => $storagePath]);
            } else {
                // Try using Laravel's Storage path
                $filename = basename($filePath);
                $laravelStoragePath = storage_path('app/private/purchase-invoice-imports/'.$filename);
                if (file_exists($laravelStoragePath)) {
                    $actualFilePath = $laravelStoragePath;
                    Log::info('File found in Laravel storage path', ['path' => $laravelStoragePath]);
                } else {
                    throw new \Exception("File not found: {$filePath} (tried: {$storagePath}, {$laravelStoragePath})");
                }
            }
        }

        DB::beginTransaction();

        try {
            // Read Excel file using Excel facade
            $data = Excel::toArray(new \App\Imports\RawArrayImport, $actualFilePath);

            if (empty($data) || empty($data[0])) {
                throw new \Exception('Cannot read data from Excel file');
            }

            $rows = $data[0];
            $headers = array_shift($rows);  // Remove first row as headers

            if (empty($headers)) {
                throw new \Exception('Cannot read headers from file');
            }

            // Clean headers - remove special characters and normalize
            $cleanHeaders = [];
            foreach ($headers as $header) {
                $cleanHeader = trim($header);
                // Remove BOM if present
                $cleanHeader = str_replace("\u{FEFF}", '', $cleanHeader);
                $cleanHeader = str_replace(['.', ' ', '-', '(', ')', '/', '°'], ['_', '_', '_', '_', '_', '_', '_'], $cleanHeader);
                $cleanHeader = strtolower($cleanHeader);
                $cleanHeaders[] = $cleanHeader;
            }

            Log::info('Purchase Invoice Excel Headers', ['original' => $headers, 'cleaned' => $cleanHeaders]);

            $rowNumber = 2;  // Start from 2 since we already read header

            foreach ($rows as $row) {
                $this->processRow($row, $cleanHeaders, $rowNumber);
                $rowNumber++;
            }

            DB::commit();

            /*
             * UPDATE practice_commissions p
             * INNER JOIN (
             *     -- La tua query originale trasformata in tabella derivata
             *     SELECT
             *         p_sub.invoice_number,
             *         p_sub.invoice_at,
             *         p_sub.Fornitore_id,
             *         s_sub.supplier_invoice_number AS matched_supplier_invoice
             *     FROM practice_commissions p_sub
             *     INNER JOIN Fornitores a_sub
             *         ON a_sub.id = p_sub.Fornitore_id
             *     INNER JOIN purchase_invoices s_sub
             *         ON s_sub.vat_number = a_sub.vat_number
             *     WHERE p_sub.invoice_number > '0'
             *       AND p_sub.is_payment = 1
             *       AND s_sub.supplier_invoice_number LIKE CONCAT('%', p_sub.invoice_number, '%')
             *     GROUP BY
             *         a_sub.name,
             *         p_sub.invoice_at,
             *         p_sub.invoice_number,
             *         p_sub.Fornitore_id,  -- Aggiunto per poter fare la join esterna in sicurezza
             *         s_sub.supplier_invoice_number,
             *         s_sub.supplier,
             *         s_sub.amount
             *     HAVING s_sub.amount = SUM(p_sub.amount)
             * ) AS dati_calcolati
             *     -- Uniamo la tabella originale con i risultati della subquery
             *     ON p.invoice_number = dati_calcolati.invoice_number
             *     AND p.invoice_at = dati_calcolati.invoice_at
             *     AND p.Fornitore_id = dati_calcolati.Fornitore_id
             *     AND p.is_payment = 1 -- Sicurezza extra per limitare l'update solo ai pagamenti
             * -- Impostiamo il nuovo valore (modifica il nome del campo se necessario)
             * SET p.alternative_number_invoice = dati_calcolati.matched_supplier_invoice;
             */

            // 1. Prepariamo la subquery con i calcoli e le join aggiuntive
            // 1. Definiamo la subquery usando gli alias esatti della tua query SQL

            /*
             * UPDATE practice_commissions c
             * INNER JOIN (
             *     -- La tua SELECT riadattata come tabella derivata
             *     SELECT
             *         c_sub.Fornitore_id,
             *         c_sub.invoice_at,
             *         s_sub.number AS matched_purchase_invoice_number
             *     FROM purchase_invoices s_sub
             *     INNER JOIN Fornitores p_sub
             *         ON p_sub.vat_number = s_sub.vat_number
             *     INNER JOIN practice_commissions c_sub
             *         ON c_sub.Fornitore_id = p_sub.id
             *         AND c_sub.invoice_at = s_sub.document_date
             *     WHERE c_sub.tipo = 'Fornitoree'
             *     GROUP BY
             *         c_sub.Fornitore_id,
             *         c_sub.invoice_at,
             *         s_sub.number,
             *         s_sub.amount
             *     HAVING s_sub.amount = SUM(c_sub.amount)
             * ) AS dati_calcolati
             *     -- Uniamo la tabella originale c usando le chiavi estratte dalla subquery
             *     ON c.Fornitore_id = dati_calcolati.Fornitore_id
             *     AND c.invoice_at = dati_calcolati.invoice_at
             *
             *
             * SET c.alternative_number_invoice = dati_calcolati.matched_purchase_invoice_number;
             */
            // 1. Costruiamo la subquery (la tabella temporanea con i dati calcolati)
            $subquery = DB::table('purchase_invoices as s_sub')
                ->select([
                    'c_sub.Fornitore_id',
                    'c_sub.invoice_at',
                    's_sub.number as matched_purchase_invoice_number',
                ])
                ->join('Fornitores as p_sub', 'p_sub.vat_number', '=', 's_sub.vat_number')
                ->join('practice_commissions as c_sub', function ($join) {
                    $join
                        ->on('c_sub.Fornitore_id', '=', 'p_sub.id')
                        ->on('c_sub.invoice_at', '=', 's_sub.document_date');
                })
                ->where('c_sub.tipo', 'Fornitoree')
                ->groupBy([
                    'c_sub.Fornitore_id',
                    'c_sub.invoice_at',
                    's_sub.number',
                    's_sub.amount',
                ])
                ->havingRaw('s_sub.amount = SUM(c_sub.amount)');

            // 2. Eseguiamo l'aggiornamento unendo la tabella principale alla subquery

            /*
             * DB::table('practice_commissions as c')
             * ->joinSub($subquery, 'dati_calcolati', function ($join) {
             *         $join
             *             ->on('c.Fornitore_id', '=', 'dati_calcolati.Fornitore_id')
             *             ->on('c.invoice_at', '=', 'dati_calcolati.invoice_at');
             *     })
             *     ->update([
             *         // Usiamo DB::raw per assegnare il valore dinamico estratto dalla join
             *         'c.alternative_number_invoice' => DB::raw('dati_calcolati.matched_purchase_invoice_number')
             *     ]);
             */
            Log::info('Purchase invoices import completed', [
                'file' => $filePath,
                'company_id' => $this->companyId,
                'results' => $this->importResults,
            ]);

            return $this->importResults;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error importing purchase invoices', [
                'file' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->importResults['errors']++;
            $this->importResults['details'][] = 'Error processing file: '.$e->getMessage();

            return $this->importResults;
        }
    }

    protected function processRow(array $row, array $headers, int $rowNumber)
    {
        try {
            // Use direct index mapping instead of array_combine for reliability
            $rowData = [];
            foreach ($headers as $index => $header) {
                $rowData[$header] = $row[$index] ?? '';
            }

            // Skip empty rows
            if (empty($rowData['nr_']) || empty($rowData['fornitore'])) {
                Log::info('Skipping row due to empty data', [
                    'row_number' => $rowNumber,
                    'nr_' => $rowData['nr_'] ?? 'NULL',
                    'fornitore' => $rowData['fornitore'] ?? 'NULL',
                    'raw_row' => $rowData,
                ]);
                $this->importResults['skipped']++;

                return;
            }

            $invoiceData = $this->mapRowToInvoiceData($rowData);

            // Add company_id
            $invoiceData['company_id'] = $this->companyId;

            // Get cleaned invoice number for duplicate checking
            $invoiceNumber = $invoiceData['number'];

            // Check if this invoice number has already been processed in the current import
            if (isset($this->processedInvoices[$invoiceNumber])) {
                Log::info('Skipping duplicate invoice within same import', [
                    'row_number' => $rowNumber,
                    'invoice_number' => $invoiceNumber,
                    'previous_row' => $this->processedInvoices[$invoiceNumber],
                ]);
                $this->importResults['skipped']++;
                $this->importResults['details'][] = "Skipped duplicate invoice: {$invoiceNumber} (row {$rowNumber})";

                return;
            }

            // Mark this invoice number as processed
            $this->processedInvoices[$invoiceNumber] = $rowNumber;

            // Check if invoice already exists in database
            $existingInvoice = PurchaseInvoice::where('company_id', $this->companyId)
                ->where('number', $invoiceNumber)
                ->first();

            if ($existingInvoice) {
                // Skip existing invoice - do not update
                Log::info('Skipping existing invoice in database', [
                    'row_number' => $rowNumber,
                    'invoice_number' => $invoiceNumber,
                    'existing_id' => $existingInvoice->id,
                ]);
                $this->importResults['skipped']++;
                $this->importResults['details'][] = "Skipped existing invoice: {$invoiceNumber} (row {$rowNumber})";
            } else {
                // Create new invoice
                $invoice = PurchaseInvoice::create($invoiceData);
                $this->importResults['imported']++;
                $this->importResults['details'][] = "Imported invoice: {$invoiceNumber} (row {$rowNumber})";
            }
        } catch (\Exception $e) {
            Log::error('Error processing row', [
                'row_number' => $rowNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->importResults['errors']++;
            $this->importResults['details'][] = "Error processing row {$rowNumber}: ".$e->getMessage();
        }
    }

    protected function mapRowToInvoiceData(array $row): array
    {
        return [
            'number' => $this->cleanString($row['nr_'] ?? null),
            'supplier_invoice_number' => $this->cleanString($row['nr__fatt__fornitore'] ?? null),
            'supplier_number' => $this->cleanString($row['nr__fornitore'] ?? null),
            'supplier' => $this->cleanString($row['fornitore'] ?? null),
            'currency_code' => $this->cleanString($row['cod__valuta'] ?? null),
            'amount' => $this->parseDecimal($row['importo'] ?? null),
            'amount_including_vat' => $this->parseDecimal($row['importo_iva_inclusa'] ?? null),
            'pay_to_cap' => $this->cleanString($row['pagare_a___cap'] ?? null),
            'pay_to_country_code' => $this->cleanString($row['pagare_a___cod__paese'] ?? null),
            'registration_date' => $this->parseDate($row['data_di_registrazione']) ?? now()->format('Y-m-d'),
            'location_code' => $this->cleanString($row['cod__ubicazione'] ?? null),
            'printed_copies' => $this->parseInteger($row['copie_stampate'] ?? 0),
            'document_date' => $this->parseDate($row['data_documento'] ?? null),
            'payment_condition_code' => $this->cleanString($row['cod__condizioni_pagam_'] ?? null),
            'due_date' => $this->parseDate($row['data_scadenza'] ?? null),
            'payment_method_code' => $this->cleanString($row['cod__metodo_di_pagamento'] ?? null),
            'residual_amount' => $this->parseDecimal($row['importo_residuo'] ?? null),
            'closed' => $this->parseBoolean($row['chiuso'] ?? null),
            'cancelled' => $this->parseBoolean($row['annullato'] ?? null),
            'corrected' => $this->parseBoolean($row['rettifica'] ?? null),
            'pay_to_address' => $this->cleanString($row['pagare_a___indirizzo'] ?? null),
            'pay_to_city' => $this->cleanString($row['pagare_a___città'] ?? null),
            'supplier_category' => $this->cleanString($row['cat__reg__fornitore'] ?? null),
            'exchange_rate' => $this->parseDecimal($row['fattore_valuta'] ?? null),
            'vat_number' => $this->cleanString($row['partita_iva'] ?? null),
            'fiscal_code' => $this->cleanString($row['codice_fiscale'] ?? null),
            'document_type' => $this->cleanString($row['tipo_documento_fattura'] ?? null),
        ];
    }

    protected function cleanString($value)
    {
        if (empty($value)) {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    protected function parseDate($value)
    {
        if (empty($value)) {
            return null;
        }

        // Handle Italian date formats
        $dateFormats = [
            'd/m/Y', 'd/m/Y', 'd-m-Y', 'd/m/Y',
            'd/m/y', 'd-m-y', 'Y-m-d',
        ];

        foreach ($dateFormats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date) {
                    return $date->format('Y-m-d');
                }
            } catch (\Exception $e) {
                // Continue to next format
            }
        }

        // Try Excel serial date format
        if (is_numeric($value)) {
            try {
                $date = Date::excelToDateTimeObject((int) $value);

                return $date->format('Y-m-d');
            } catch (\Exception $e) {
                // Continue to return null
            }
        }

        return null;
    }

    protected function parseDateTime($value)
    {
        if (empty($value)) {
            return null;
        }

        // Handle Italian datetime formats
        $dateTimeFormats = [
            'd/m/Y H:i', 'd/m/Y H:i:s', 'd-m-Y H:i',
            'd/m/y H:i', 'd-m-y H:i', 'Y-m-d H:i:s',
        ];

        foreach ($dateTimeFormats as $format) {
            try {
                $dateTime = Carbon::createFromFormat($format, $value);
                if ($dateTime) {
                    return $dateTime->format('Y-m-d H:i:s');
                }
            } catch (\Exception $e) {
                // Continue to next format
            }
        }

        return null;
    }

    protected function parseDecimal($value)
    {
        if (empty($value) || $value === '' || $value === null) {
            return 0;
        }

        // If it's already a float, return it directly
        if (is_float($value)) {
            return $value;
        }

        // Handle Italian format: 29.582,24 -> 29582.24
        // First, remove thousands separators (dots) only if there's a comma for decimal
        if (is_string($value) && strpos($value, ',') !== false) {
            $parts = explode(',', $value);
            $integer_part = str_replace('.', '', $parts[0]);
            $decimal_part = $parts[1] ?? '0';
            $value = $integer_part.'.'.$decimal_part;
        } else {
            // If no comma, just remove dots (might be thousands separators)
            $value = str_replace('.', '', $value);
        }

        return (float) $value;
    }

    protected function parseInteger($value)
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    protected function parseBoolean($value)
    {
        if (empty($value)) {
            return null;
        }

        // Handle Excel TRUE/FALSE strings and formulas
        $value = strtoupper(trim($value));

        if ($value === 'TRUE' || $value === 'VERO' || $value === '=TRUE()') {
            return true;
        } elseif ($value === 'FALSE' || $value === 'FALSO' || $value === '=FALSE()') {
            return false;
        }

        return null;
    }

    public function getResults(): array
    {
        return $this->importResults;
    }

    /**
     * Match Purchase invoices to Fornitores by VAT number
     * Updates the relationship for matching invoices
     */
    public function matchFornitoresByVatNumber($companyId = null): void
    {
        // Use provided companyId or fall back to instance property
        $companyId = $companyId ?? $this->companyId;

        $matchedCount = 0;

        Log::info('Starting mass Fornitore matching by exact VAT number', [
            'company_id' => $companyId,
        ]);

        // Esegue un singolo UPDATE con JOIN a livello di database
        $affectedRows = DB::table('purchase_invoices as pi')
            ->join('Fornitores as a', 'pi.vat_number', '=', 'a.vat_number')
            ->where('pi.company_id', $companyId)
            ->whereNotNull('pi.vat_number')
            ->whereNull('pi.invoiceable_id')
            ->update([
                'pi.invoiceable_id' => DB::raw('a.id'),
                'pi.invoiceable_type' => Fornitore::class,
            ]);

        Log::info('Fornitore matching completed', [
            'company_id' => $companyId,
            'matched_and_updated_invoices' => $affectedRows,
        ]);
        try {
            // Get all Purchase invoices for this company that have a VAT number but no Fornitore relationship
            $invoices = PurchaseInvoice::where('company_id', $companyId)
                ->whereNotNull('vat_number')
                ->whereNull('invoiceable_id')
                ->get();

            Log::info('Starting Fornitore matching by VAT number', [
                'company_id' => $companyId,
                'invoices_to_check' => $invoices->count(),
            ]);

            foreach ($invoices as $invoice) {
                // Clean VAT number for comparison
                $cleanVatNumber = $this->cleanVatNumber($invoice->vat_number);

                if (empty($cleanVatNumber)) {
                    continue;
                }

                // Find Fornitore with matching VAT number
                $Fornitore = $this->findFornitoreByVatNumber($cleanVatNumber, $companyId);

                if (! $Fornitore && ! empty($invoice->supplier)) {
                    $Fornitore = $this->findFornitoreByNameSimilarity($invoice->supplier, $companyId);
                }

                // Se il Fornitore ha un VAT number di 10 caratteri, confronta solo i primi 10
                if (! $Fornitore && strlen($cleanVatNumber) >= 10) {
                    $first10Chars = substr($cleanVatNumber, 0, 10);
                    $Fornitore = Fornitore::where('company_id', $companyId)
                        ->whereRaw('LENGTH(vat_number) >= 10')
                        ->whereRaw('SUBSTRING(vat_number, 1, 10) = ?', [$first10Chars])
                        ->first();

                    if (! $Fornitore) {
                        $Fornitore = $this->findFornitoreByNameSimilarity($invoice->supplier, $companyId);
                    }

                    if ($Fornitore) {
                        // Rettifica il VAT number del Fornitore con quello completo della fattura
                        $Fornitore->update(['vat_number' => $cleanVatNumber, 'contoCOGE' => $invoice->supplier_number]);
                    }
                }

                if ($Fornitore) {
                    // Update the invoice with the Fornitore relationship
                    $invoice->update([
                        'invoiceable_type' => Fornitore::class,
                        'invoiceable_id' => $Fornitore->id,
                    ]);

                    $matchedCount++;
                }
            }

            // Update import results
            $this->importResults['Fornitore_matches'] = $matchedCount;
            $this->importResults['details'][] = "Matched {$matchedCount} invoices to Fornitores by VAT number";
        } catch (\Exception $e) {
            Log::error('Fornitore matching failed', [
                'company_id' => $this->companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->importResults['Fornitore_match_errors'] = ($this->importResults['Fornitore_match_errors'] ?? 0) + 1;
            $this->importResults['details'][] = 'Fornitore matching error: '.$e->getMessage();
        }
    }

    /**
     * Clean and normalize VAT number for comparison
     */
    protected function cleanVatNumber(string $vatNumber): string
    {
        // Remove spaces, dots, dashes, and common Italian VAT formatting
        $cleaned = preg_replace('/[\s\.\-_]/', '', $vatNumber);

        // Remove country prefix if present (IT for Italy)
        if (str_starts_with(strtoupper($cleaned), 'IT')) {
            $cleaned = substr($cleaned, 2);
        }

        // Remove any remaining non-alphanumeric characters
        $cleaned = preg_replace('/[^A-Z0-9]/', '', strtoupper($cleaned));

        return $cleaned;
    }

    /**
     * Get variations of VAT number for flexible matching
     */
    protected function getVatNumberVariations(string $vatNumber): array
    {
        $variations = [$vatNumber];

        // Add with country prefix
        if (! str_starts_with(strtoupper($vatNumber), 'IT')) {
            $variations[] = 'IT'.$vatNumber;
        }

        // Add with spaces and formatting variations
        $formatted = preg_replace('/([A-Z0-9]{2})/', '$1 ', $vatNumber);
        $formatted = trim($formatted);
        if ($formatted !== $vatNumber) {
            $variations[] = $formatted;
        }

        return array_unique($variations);
    }

    /**
     * Match Purchase invoices to clients by VAT number
     * Updates the relationship for matching invoices
     */
    public function matchClientsByVatNumber($companyId = null): void
    {
        // Use provided companyId or fall back to instance property
        $companyId = $companyId ?? $this->companyId;

        $matchedCount = 0;

        try {
            // Get all Purchase invoices for this company that have a VAT number but no client relationship
            $invoices = PurchaseInvoice::where('company_id', $companyId)
                ->whereNotNull('vat_number')
                ->whereNull('invoiceable_id')
                ->get();

            foreach ($invoices as $invoice) {
                // Clean VAT number for comparison
                $vatNumber = $invoice->vat_number;

                $cleanVatNumber = $this->cleanVatNumber($vatNumber);

                if (empty($cleanVatNumber)) {
                    continue;
                }

                // Find Fornitore with matching VAT number
                $Fornitore = $this->findFornitoreByVatNumber($cleanVatNumber, $companyId);

                if ($Fornitore) {
                    continue;
                }

                // Find client with matching VAT number
                $client = $this->findClientByVatNumber($cleanVatNumber, $companyId);

                if (! $client && ! empty($invoice->supplier)) {
                    // Try to match by customer name similarity
                    $client = $this->findClientByNameSimilarity($invoice->supplier, $companyId);
                }

                if (! $client && ! empty($invoice->supplier)) {
                    // Try to match by full name (name + first_name)
                    $client = $this->findClientByFullName($invoice->supplier, $companyId);
                }

                if ($client) {
                    // Update the invoice with the client relationship
                    $invoice->update([
                        'invoiceable_type' => Client::class,
                        'invoiceable_id' => $client->id,
                    ]);

                    $matchedCount++;
                } else {
                    // Create new client if no matches found
                    $newClient = $this->createClientFromInvoice($invoice, $companyId);

                    if ($newClient) {
                        // Update the invoice with the new client relationship
                        $invoice->update([
                            'invoiceable_type' => Client::class,
                            'invoiceable_id' => $newClient->id,
                        ]);

                        $this->importResults['clients_created'] = ($this->importResults['clients_created'] ?? 0) + 1;
                    }
                }
            }

            Log::info('Client matching completed', [
                'company_id' => $companyId,
                'total_checked' => $invoices->count(),
                'matched' => $matchedCount,
            ]);

            // Update import results
            $this->importResults['client_matches'] = $matchedCount;
            $this->importResults['details'][] = "Matched {$matchedCount} invoices to clients by VAT number";
        } catch (\Exception $e) {
            Log::error('Client matching failed', [
                'company_id' => $this->companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->importResults['client_match_errors'] = ($this->importResults['client_match_errors'] ?? 0) + 1;
            $this->importResults['details'][] = 'Client matching error: '.$e->getMessage();
        }
    }

    /**
     * Create a new client from invoice data
     */
    protected function createClientFromInvoice(PurchaseInvoice $invoice, $companyId): ?Client
    {
        try {
            $clientData = [
                'company_id' => $companyId,
                'name' => $invoice->supplier,
                'tax_code' => $invoice->fiscal_code,
                'vat_number' => $invoice->vat_number,
                'contoCOGE' => $invoice->supplier_number,
                'is_client' => false,
                'is_company' => true,  // Assume customers are companies
                'status' => 'active',
            ];

            $client = Client::create($clientData);

            // Check if address with address_type_id => 5 already exists
            $existingAddress = $client
                ->addresses()
                ->where('address_type_id', 5)
                ->first();

            if (! $existingAddress) {
                $address = $client->addresses()->create([
                    'address_type_id' => 5,
                    'name' => 'Fatturazione',
                    'street' => $invoice->pay_to_address,
                    'city' => $invoice->pay_to_city,
                    //   'zip_code' => $invoice->ship_to_cap,
                ]);
            }

            Log::info('Successfully created new client', [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'invoice_number' => $invoice->number,
                'tax_code' => $client->tax_code,
            ]);

            return $client;
        } catch (\Exception $e) {
            Log::error('Failed to create client from invoice', [
                'invoice_number' => $invoice->number,
                'supplier' => $invoice->supplier,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Find client by matching VAT number (checks both vat_number and tax_code)
     */
    protected function findClientByVatNumber(string $vatNumber, string $companyId): ?Client
    {
        if (empty($vatNumber)) {
            return null;
        }

        $Fornitore = $this->findFornitoreByVatNumber($vatNumber, $companyId);
        if ($Fornitore) {
            return $Fornitore;
        }

        // Try variations
        $variations = $this->getVatNumberVariations($vatNumber, $companyId);

        foreach ($variations as $variation) {
            try {
                $client = Client::where('company_id', $companyId)
                    ->where('tax_code', $variation)
                    ->first();

                if ($client) {
                    return $client;
                }
            } catch (\Exception $e) {
                // Column vat_number doesn't exist, try tax_code
            }

            $client = Client::where('company_id', $companyId)
                ->where('tax_code', $variation)
                ->first();

            if ($client) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Find Fornitore by VAT number with flexible matching
     */
    protected function findFornitoreByVatNumber(string $vatNumber, string $companyId): ?Fornitore
    {
        // Try exact match first
        $Fornitore = Fornitore::where('vat_number', $vatNumber)
            ->where('company_id', $companyId)
            ->first();

        if ($Fornitore) {
            return $Fornitore;
        }

        // Try cleaned versions
        $cleanedVariations = $this->getVatNumberVariations($vatNumber);

        foreach ($cleanedVariations as $variation) {
            $Fornitore = Fornitore::where('vat_number', $variation)
                ->where('company_id', $companyId)
                ->first();

            if ($Fornitore) {
                return $Fornitore;
            }
        }

        // Try to find by tax_code if vat_number column doesn't exist
        $variations = $this->getVatNumberVariations($vatNumber);

        foreach ($variations as $variation) {
            try {
                $client = Client::where('company_id', $companyId)
                    ->where('vat_number', $variation)
                    ->first();

                if ($client) {
                    continue;
                }
            } catch (\Exception $e) {
                // Column vat_number doesn't exist, try tax_code
            }

            $client = Client::where('company_id', $companyId)
                ->where('tax_code', $variation)
                ->first();

            if ($client) {
                continue;
            }
        }

        return null;
    }

    /**
     * Find Fornitore by name similarity using fuzzy matching
     */
    protected function findFornitoreByNameSimilarity($FornitoreName, $companyId): ?Fornitore
    {
        if (empty($FornitoreName)) {
            return null;
        }

        // Get all Fornitores for this company
        $Fornitores = Fornitore::where('company_id', $companyId)
            ->whereNotNull('name')
            ->get();

        $bestMatch = null;
        $bestScore = 0;
        $similarityThreshold = 70;  // 70% similarity threshold

        foreach ($Fornitores as $Fornitore) {
            $score = $this->calculateSimilarity($FornitoreName, $Fornitore->name);

            if ($score > $bestScore && $score >= $similarityThreshold) {
                $bestScore = $score;
                $bestMatch = $Fornitore;
            }
        }

        return $bestMatch;
    }

    /**
     * Find client by name similarity using fuzzy matching
     */
    protected function findClientByNameSimilarity(string $clientName, string $companyId): ?Client
    {
        if (empty($clientName)) {
            return null;
        }

        // Get all clients for this company
        $clients = Client::where('company_id', $companyId)
            ->whereNotNull('name')
            ->get();

        $bestMatch = null;
        $bestScore = 0;
        $similarityThreshold = 70;  // 70% similarity threshold

        foreach ($clients as $client) {
            $score = $this->calculateSimilarity($clientName, $client->name);

            if ($score > $bestScore && $score >= $similarityThreshold) {
                $bestScore = $score;
                $bestMatch = $client;
            }
        }

        return $bestMatch;
    }

    /**
     * Calculate similarity between two strings using Levenshtein distance
     */
    protected function calculateSimilarity(string $string1, string $string2): int
    {
        $string1 = strtolower(trim($string1));
        $string2 = strtolower(trim($string2));

        if (empty($string1) || empty($string2)) {
            return 0;
        }

        // Remove common company suffixes for better matching
        $suffixes = ['s.r.l.', 'srl', 's.p.a.', 'spa', 'ltd', 'limited', 'inc', 'llc', 'gmbh'];
        foreach ($suffixes as $suffix) {
            $string1 = preg_replace('/\b'.preg_quote($suffix).'\b/i', '', $string1);
            $string2 = preg_replace('/\b'.preg_quote($suffix).'\b/i', '', $string2);
        }

        // Clean up extra spaces
        $string1 = preg_replace('/\s+/', ' ', trim($string1));
        $string2 = preg_replace('/\s+/', ' ', trim($string2));

        // Use Levenshtein distance for similarity calculation
        $distance = levenshtein($string1, $string2);
        $maxLength = max(strlen($string1), strlen($string2));

        if ($maxLength === 0) {
            return 100;
        }

        $similarity = 100 - (($distance / $maxLength) * 100);

        return (int) round($similarity);
    }

    /**
     * Find client by matching full name (name + first_name)
     */
    protected function findClientByFullName(string $customerName, string $companyId): ?Client
    {
        if (empty($customerName)) {
            return null;
        }

        // Get all clients for this company
        $clients = Client::where('company_id', $companyId)
            ->whereNotNull('name')
            ->get();

        foreach ($clients as $client) {
            // Build full name from client data
            $fullName = trim($client->name);

            if (! empty($client->first_name)) {
                $fullName = trim($client->name.' '.$client->first_name);
            }

            // Exact match first
            if (strcasecmp($fullName, $customerName) === 0) {
                Log::info('Found exact full name match', [
                    'supplier' => $customerName,
                    'client_full_name' => $fullName,
                    'client_id' => $client->id,
                ]);

                return $client;
            }

            // Check if client name is contained in customer name (inverse matching)
            if (stripos($client->name, $customerName) !== false) {
                Log::info('Found inverse partial name match', [
                    'supplier' => $customerName,
                    'client_name' => $client->name,
                    'client_id' => $client->id,
                ]);

                return $client;
            }

            // Clean both strings for better comparison (remove common suffixes)
            $cleanCustomerName = $this->cleanCompanyName($customerName);
            $cleanClientName = $this->cleanCompanyName($client->name);

            if (strcasecmp($cleanCustomerName, $cleanClientName) === 0) {
                Log::info('Found cleaned name match', [
                    'supplier' => $customerName,
                    'client_clean_name' => $cleanClientName,
                    'client_id' => $client->id,
                ]);

                return $client;
            }
        }

        return null;
    }

    /**
     * Clean company name by removing common suffixes and formatting
     */
    protected function cleanCompanyName(string $name): string
    {
        // Remove common company suffixes for better matching
        $suffixes = ['SPA', 'SRL', 'SNC', 'S.P.A.', 'SNC'];
        $cleaned = preg_replace('/\b'.implode('|', array_map('preg_quote', $suffixes)).'\b/i', '', $name);

        // Clean up extra spaces and standardize
        $cleaned = preg_replace('/\s+/', ' ', trim($cleaned));
        $cleaned = strtoupper(trim($cleaned));

        return $cleaned;
    }
}
