<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Company;
use App\Models\SalesInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class SalesInvoiceCreditNoteImportService
{
    protected $companyId;

    protected $filename;

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

        // Handle storage path
        $actualFilePath = $filePath;
        if (! file_exists($filePath)) {
            // Only try storage path, not public path
            $storagePath = str_replace('public/', 'storage/app/private/sales-invoice-imports/', $filePath);
            if (file_exists($storagePath)) {
                $actualFilePath = $storagePath;
                Log::info('File found in storage path', ['path' => $storagePath]);
            } else {
                throw new \Exception("File not found: {$filePath} (tried: {$storagePath})");
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
                // Replace special chars but keep dots and meaningful characters
                $cleanHeader = str_replace([' ', '-', '(', ')', '/', '°'], ['_', '_', '_', '_', '_'], $cleanHeader);
                $cleanHeaders[] = $cleanHeader;
            }

            Log::info('Sales Invoice Excel Headers', ['original' => $headers, 'cleaned' => $cleanHeaders, 'file_type' => pathinfo($actualFilePath, PATHINFO_EXTENSION)]);

            // Debug: mostra tutti i dati della prima riga
            $rowNumber = 2;  // Start from 2 since we already read header

            foreach ($rows as $row) {
                $this->processRow($row, $cleanHeaders, $rowNumber);
                $rowNumber++;
            }

            DB::commit();

            Log::info('Sales invoices import completed', [
                'file' => $filePath,
                'company_id' => $this->companyId,
                'results' => $this->importResults,
            ]);
            $this->matchEntrataCommissionsToSalesInvoices();

            return $this->importResults;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
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

            // Skip empty rows - only require number for credit notes
            if (empty($rowData['Nr.'])) {
                Log::info('Skipping row due to empty data', [
                    'row_number' => $rowNumber,
                    'Nr.' => $rowData['Nr.'] ?? 'NULL',
                    'Nome_cliente' => $rowData['Nome_cliente'] ?? 'NULL',
                    'raw_row' => $rowData,
                ]);
                $this->importResults['skipped']++;

                return;
            }

            // Use customer name from Nome_cliente field
            if (empty($rowData['Nome_cliente'])) {
                $rowData['customer_number'] = 'CLIENTE_GENERICO';
                $rowData['customer_name'] = 'Generic Customer';
            } else {
                $rowData['customer_name'] = $rowData['Nome_cliente'];
            }

            // Debug for FVI25-00100
            if ($rowData['Nr.'] === 'FVI25-00100') {
                Log::info('FVI25-00100 raw data', [
                    'raw_row_data' => $rowData,
                    'Importo_raw' => $rowData['Importo'] ?? 'NULL',
                    'Importo_IVA_inclusa_raw' => $rowData['Importo_IVA_inclusa'] ?? 'NULL',
                    'Importo_residuo_raw' => $rowData['Importo_residuo'] ?? 'NULL',
                ]);
            }

            $invoiceData = $this->mapRowToInvoiceData($rowData);

            // Add company_id
            $invoiceData['company_id'] = $this->companyId;

            // Check if invoice already exists
            $existingInvoice = SalesInvoice::where('company_id', $this->companyId)
                ->where('number', $invoiceData['number'])
                ->first();

            if ($existingInvoice) {
                $existingInvoice->update($invoiceData);
                $this->importResults['updated']++;
                $this->importResults['details'][] = "Updated invoice: {$invoiceData['number']} (row $rowNumber)";
            } else {
                $invoice = SalesInvoice::create($invoiceData);
                $this->importResults['imported']++;
                $this->importResults['details'][] = "Imported invoice: {$invoiceData['number']} (row $rowNumber)";
            }
        } catch (\Exception $e) {
            $this->importResults['errors']++;
            $errorDetails = "Error processing row $rowNumber: ".$e->getMessage();
            $this->importResults['details'][] = $errorDetails;
            Log::error('Sales invoice import error', [
                'row_number' => $rowNumber,
                'row' => $row,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function mapRowToInvoiceData(array $row): array
    {
        return [
            'number' => $this->cleanString($row['Nr.'] ?? null),
            'order_number' => $this->cleanString($row['Vendere_a___Nr._cliente'] ?? null),
            'customer_number' => $this->cleanString($row['Vendere_a___Nr._cliente'] ?? null),
            'customer_name' => $this->cleanString($row['Nome_cliente'] ?? null),
            'currency_code' => $this->cleanString($row['Cod._valuta'] ?? null),
            'due_date' => $this->parseDate($row['Data_scadenza'] ?? null),
            'amount' => $this->parseDecimal($row['Importo'] ?? null),
            'amount_including_vat' => $this->parseDecimal($row['Importo_IVA_inclusa'] ?? null),
            'residual_amount' => $this->parseDecimal($row['Importo_residuo'] ?? null),
            'ship_to_code' => $this->cleanString($row['Spedire_a___Codice'] ?? null),
            'ship_to_cap' => $this->cleanString($row['Spedire_a___CAP'] ?? null),
            'registration_date' => $this->parseDate($row['Data_di_registrazione'] ?? null) ?? now()->format('Y-m-d'),
            'agent_code' => $this->cleanString($row['Cod._agente'] ?? null),
            'cdc_code' => $this->cleanString($row['Cdc_Codice'] ?? null),
            'dimensional_link_code' => $this->cleanString($row['Cod._colleg._dimen._2'] ?? null),
            'location_code' => $this->cleanString($row['Cod._ubicazione'] ?? null),
            'printed_copies' => $this->parseInteger($row['Copie_stampate'] ?? 0),
            'payment_condition_code' => $this->cleanString($row['Cod._condizioni_pagam.'] ?? null),
            'closed' => $this->parseBoolean($row['Pagato'] ?? null),
            'cancelled' => $this->parseBoolean($row['Annullato'] ?? null),
            'corrected' => $this->parseBoolean($row['Rettifica'] ?? null),
            'email_sent' => $this->parseBoolean($row['E_mail_inviata'] ?? null),
            'email_sent_at' => $this->parseDateTime($row['Data_ora_invio_mail'] ?? null),
            'bill_to_address' => $this->cleanString($row['Fatturare_a___Indirizzo'] ?? null),
            'bill_to_city' => $this->cleanString($row['Fatturare_a___Città'] ?? null),
            'bill_to_province' => $this->cleanString($row['Provincia_di_fatturazione'] ?? null),
            'ship_to_address' => $this->cleanString($row['Spedire_a___Indirizzo'] ?? null),
            'ship_to_city' => $this->cleanString($row['Spedire_a___Città'] ?? null),
            'payment_method_code' => $this->cleanString($row['Cod._metodo_di_pagamento'] ?? null),
            'customer_category' => $this->cleanString($row['Cat._reg._cliente'] ?? null),
            'exchange_rate' => $this->parseDecimal($row['Fattore_valuta'] ?? null),
            'vat_number' => $this->cleanString($row['Partita_IVA'] ?? null),
            'bank_account' => $this->cleanString($row['C_C_bancario'] ?? null),
            'document_residual_amount' => $this->parseDecimal($row['Importo_residuo_documento'] ?? null),
            'document_type' => $this->cleanString($row['Tipo_di_documento_Fattura'] ?? null),
            'credit_note_linked' => $this->cleanString($row['Nota_Credito_Origine'] ?? null),
            'in_order' => $this->parseBoolean($row['Flg_In_Commessa'] ?? null),
            'customer_name_number' => $this->cleanString($row['Nr._fornitore'] ?? null),
            'customer_name_description' => $this->cleanString($row['Descrizione_fornitore'] ?? null),
            'purchase_invoice_origin' => $this->cleanString($row['Nota_Credito_Origine'] ?? null),
            'sent_to_sdi' => $this->parseBoolean($row['Inviato_allo_SDI'] ?? null),
        ];
    }

    protected function cleanString($value)
    {
        if (empty($value)) {
            return null;
        }

        return trim($value);
    }

    protected function parseDecimal($value)
    {
        if (empty($value)) {
            return 0;
        }

        // Debug logging for FVI25-00100
        if (is_string($value) && (strpos($value, '29582') !== false || strpos($value, '29582,24') !== false)) {
            Log::info('parseDecimal debug for FVI25-00100', [
                'raw_value' => $value,
                'type' => gettype($value),
                'before_replacement' => $value,
            ]);
        }

        // If it's already a float, return it directly
        if (is_float($value)) {
            $result = $value;

            // Debug logging for FVI25-00100
            if ($result == 29582.24 || $result == 29582 || $result == 2958224) {
                Log::info('parseDecimal result for FVI25-00100 (already float)', [
                    'result' => $result,
                    'formatted_result' => number_format($result, 2, ',', '.'),
                ]);
            }

            return $result;
        }

        // Handle Italian format: 29.582,24 -> 29582.24
        // First, remove thousands separators (dots) only if there's a comma for decimal
        if (strpos($value, ',') !== false) {
            $parts = explode(',', $value);
            $integer_part = str_replace('.', '', $parts[0]);
            $decimal_part = $parts[1] ?? '0';
            $value = $integer_part.'.'.$decimal_part;
        } else {
            // If no comma, just remove dots (might be thousands separators)
            $value = str_replace('.', '', $value);
        }

        $result = (float) $value;

        // Debug logging for FVI25-00100
        if ($result == 29582.24 || $result == 29582 || $result == 2958224) {
            Log::info('parseDecimal result for FVI25-00100', [
                'result' => $result,
                'formatted_result' => number_format($result, 2, ',', '.'),
            ]);
        }

        return $result;
    }

    protected function parseInteger($value)
    {
        if (empty($value)) {
            return 0;
        }

        return (int) $value;
    }

    protected function parseDate($value)
    {
        if (empty($value)) {
            return null;
        }

        // Handle Italian format: 30/12/2025 -> 2025-12-30
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches)) {
            return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
        }

        return null;
    }

    protected function parseDateTime($value)
    {
        if (empty($value)) {
            return null;
        }

        // Try to parse various datetime formats
        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseBoolean($value)
    {
        if (empty($value)) {
            return false;
        }

        return in_array(strtolower($value), ['vero', 'true', '1', 'si', 'yes']);
    }

    /**
     * Allinea le provvigioni di entrata alle fatture di vendita.
     *
     * Le provvigioni di tipo Istituto fanno capo a `clientis` (nome = istituto_finanziario, partita IVA in `piva`),
     * quelle di tipo Cliente a `clients` (nome = denominazione_riferimento, partita IVA in `vat_number`).
     * Per ciascun soggetto la partita IVA si ricava dalla fattura di vendita con stessa data e importo totale
     * (solo se quella presente è vuota o non lunga 11, 13 o 16 caratteri) e poi si assegna a `provvigioni.invoice_number` il numero della fattura corrispondente.
     */
    protected function matchEntrataCommissionsToSalesInvoices(): void
    {
        $coreDatabase = DB::connection(config('unico-core.connection'))->getDatabaseName();

        $subjects = [
            'Istituto' => ['table' => 'clientis', 'vat' => 'piva', 'name' => 'istituto_finanziario'],
            'Cliente' => ['table' => 'clients', 'vat' => 'vat_number', 'name' => 'denominazione_riferimento'],
        ];

        foreach ($subjects as $tipo => $subject) {
            $table = "`{$coreDatabase}`.`{$subject['table']}`";
            $vat = $subject['vat'];
            $name = $subject['name'];

            DB::update("
                UPDATE {$table} c
                JOIN (
                    SELECT p.{$name} AS subject_name, s.vat_number
                    FROM provvigioni p
                    INNER JOIN sales_invoices s ON s.registration_date = p.data_fattura
                    WHERE p.entrata_uscita = 'Entrata'
                      AND p.tipo = ?
                      AND p.annullato = 0
                      AND YEAR(p.data_fattura) > 2024
                      AND s.vat_number IS NOT NULL
                    GROUP BY p.{$name}, p.data_fattura, p.n_fattura, s.amount, s.vat_number
                    HAVING s.amount = SUM(p.importo)
                ) src ON c.name = src.subject_name
                SET c.{$vat} = src.vat_number
                WHERE c.{$vat} IS NULL OR c.{$vat} = '' OR CHAR_LENGTH(c.{$vat}) NOT IN (11, 13, 16)
            ", [$tipo]);

            DB::update("
                UPDATE provvigioni p
                INNER JOIN (
                    SELECT p_inner.{$name} AS subject_name, p_inner.data_fattura, s.number AS invoice_ref_number
                    FROM provvigioni p_inner
                    INNER JOIN {$table} c ON c.name = p_inner.{$name}
                    INNER JOIN sales_invoices s ON s.vat_number = c.{$vat}
                    WHERE p_inner.entrata_uscita = 'Entrata'
                      AND p_inner.tipo = ?
                      AND YEAR(p_inner.data_fattura) = 2025
                      AND p_inner.invoice_number IS NULL
                    GROUP BY c.id, c.name, c.{$vat}, p_inner.{$name}, p_inner.data_fattura, s.registration_date, s.number
                    HAVING ABS(SUM(p_inner.importo) - SUM(s.amount)) < 100
                ) matched_data ON p.{$name} = matched_data.subject_name
                    AND p.data_fattura = matched_data.data_fattura
                SET p.invoice_number = matched_data.invoice_ref_number
                WHERE p.entrata_uscita = 'Entrata'
                  AND p.tipo = ?
                  AND p.invoice_number IS NULL
            ", [$tipo, $tipo]);
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
     * Match Sales invoices to clients by VAT number
     * Updates the relationship for matching invoices
     */
    public function matchClientsByVatNumber($companyId = null): void
    {
        // Use provided companyId or fall back to instance property
        $companyId = $companyId ?? $this->companyId;

        $matchedCount = 0;

        try {
            // Get all Sales invoices for this company that have a VAT number but no client relationship
            $invoices = SalesInvoice::where('company_id', $companyId)
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

                // Find client with matching VAT number
                $client = $this->findClientByVatNumber($cleanVatNumber, $companyId);

                if (! $client && ! empty($invoice->customer_name)) {
                    // Try to match by customer name similarity
                    $client = $this->findClientByNameSimilarity($invoice->customer_name, $companyId);
                }

                if (! $client && ! empty($invoice->customer_name)) {
                    // Try to match by full name (name + first_name)
                    $client = $this->findClientByFullName($invoice->customer_name, $companyId);
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
    protected function createClientFromInvoice(SalesInvoice $invoice, $companyId): ?Client
    {
        try {
            $clientData = [
                'company_id' => $companyId,
                'name' => $invoice->customer_name,
                'tax_code' => $invoice->fiscal_code,
                'vat_number' => $invoice->vat_number,
                'contoCOGE' => $invoice->customer_name_number,
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
                    'street' => $invoice->bill_to_address,
                    'city' => $invoice->bill_to_city,
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
                'customer_name' => $invoice->customer_name,
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

        // Try to find by tax_code
        $client = Client::where('company_id', $companyId)
            ->where('tax_code', $vatNumber)
            ->first();

        if ($client) {
            Log::info('Found client by tax_code', [
                'vat_number' => $vatNumber,
                'client_id' => $client->id,
                'client_name' => $client->name,
                'tax_code' => $client->tax_code,
            ]);

            return $client;
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
                    'customer_name' => $customerName,
                    'client_full_name' => $fullName,
                    'client_id' => $client->id,
                ]);

                return $client;
            }

            // Check if client name is contained in customer name (inverse matching)
            if (stripos($client->name, $customerName) !== false) {
                Log::info('Found inverse partial name match', [
                    'customer_name' => $customerName,
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
                    'customer_name' => $customerName,
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
