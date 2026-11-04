<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Fornitore;
use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class PurchaseCreditNoteImportService
{
    protected $companyId;

    protected $filename;

    protected $importResults = [
        'imported' => 0,
        'updated' => 0,
        'errors' => 0,
        'skipped' => 0,
        'details' => [],
    ];

    public function __construct($companyId = null, $filename = null)
    {
        $this->companyId = $companyId;
        $this->filename = $filename;
    }

    /**
     * Import purchase credit notes from Excel file
     *
     * @param  string  $filePath  Path to the file
     * @param  string  $companyId  Company ID to assign to credit notes
     * @return array Import results
     */
    public function import(string $filePath, ?string $companyId = null): array
    {
        $this->companyId = $companyId ?: $this->companyId;

        // Extract filename from path if not provided
        if (! $this->filename) {
            $this->filename = basename($filePath);
        }

        $this->importResults = [
            'imported' => 0,
            'updated' => 0,
            'errors' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        try {
            Log::info('Starting purchase credit note import', [
                'file_path' => $filePath,
                'filename' => $this->filename,
                'company_id' => $this->companyId,
            ]);

            // Read Excel file
            $data = Excel::toArray(new \App\Imports\RawArrayImport, $filePath);

            if (empty($data) || ! isset($data[0])) {
                throw new \Exception('File is empty or invalid format');
            }

            $rows = $data[0];

            // Skip header row if present
            if ($this->isHeaderRow($rows[0])) {
                array_shift($rows);
            }

            Log::info('Processing rows', [
                'total_rows' => count($rows),
            ]);

            DB::beginTransaction();

            foreach ($rows as $index => $row) {
                $rowNumber = $index + 1;

                try {
                    $this->processRow($row, $rowNumber);
                } catch (\Exception $e) {
                    $this->importResults['errors']++;
                    $this->importResults['details'][] = "Row {$rowNumber}: ".$e->getMessage();

                    Log::error('Error processing row', [
                        'row_number' => $rowNumber,
                        'row_data' => $row,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            DB::commit();

            Log::info('Purchase credit note import completed', $this->importResults);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Purchase credit note import failed', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->importResults['errors']++;
            $this->importResults['details'][] = 'Import failed: '.$e->getMessage();
        }

        return $this->importResults;
    }

    /**
     * Process a single row from the Excel file
     */
    protected function processRow(array $row, int $rowNumber): void
    {
        // Skip empty rows
        if ($this->isEmptyRow($row)) {
            $this->importResults['skipped']++;

            return;
        }

        // Extract data from row (adjust column indices based on your Excel structure)
        $creditNoteData = $this->extractCreditNoteData($row);

        // Validate required fields
        if (empty($creditNoteData['number']) || empty($creditNoteData['document_date'])) {
            throw new \Exception('Missing required fields: number or document_date');
        }

        // Check if credit note already exists
        $existingCreditNote = PurchaseInvoice::where('company_id', $this->companyId)
            ->where('number', $creditNoteData['number'])
            ->where('document_date', $creditNoteData['document_date'])
            ->first();

        if ($existingCreditNote) {
            // Update existing record
            $existingCreditNote->update($creditNoteData);
            $this->importResults['updated']++;

            Log::info('Updated existing purchase credit note', [
                'row_number' => $rowNumber,
                'credit_note_id' => $existingCreditNote->id,
                'number' => $creditNoteData['number'],
            ]);
        } else {
            // Create new credit note
            $creditNote = PurchaseInvoice::create([
                'company_id' => $this->companyId,
                ...$creditNoteData,
            ]);

            $this->importResults['imported']++;

            Log::info('Created new purchase credit note', [
                'row_number' => $rowNumber,
                'credit_note_id' => $creditNote->id,
                'number' => $creditNoteData['number'],
            ]);
        }
    }

    /**
     * Extract credit note data from Excel row
     * Adjust this method based on your Excel column structure
     */
    protected function extractCreditNoteData(array $row): array
    {
        return [
            'number' => $this->cleanString($row[0] ?? ''),  // Nr.
            'supplier_number' => $this->cleanString($row[1] ?? ''),  // Acquistare da - Nr. for.
            'supplier' => $this->cleanString($row[2] ?? ''),  // Acquistare da - Nome for.
            'currency_code' => $this->cleanString($row[3] ?? ''),  // Cod. valuta
            'due_date' => $this->parseDate($row[4] ?? null),  // Data scadenza
            'amount' => $this->parseAmount($row[5] ?? 0),  // Importo
            'amount_including_vat' => $this->parseAmount($row[6] ?? 0),  // Importo IVA inclusa
            'residual_amount' => $this->parseAmount($row[7] ?? 0),  // Importo residuo
            'closed' => $this->parseBoolean($row[8] ?? null),  // Pagato
            'cancelled' => $this->parseBoolean($row[9] ?? null),  // Annullato
            'corrected' => $this->parseBoolean($row[10] ?? null),  // Rettifica
            'pay_to_cap' => $this->cleanString($row[11] ?? ''),  // Pagare a - CAP
            'pay_to_country_code' => $this->cleanString($row[12] ?? ''),  // Pagare a - Cod. paese
            'registration_date' => $this->parseDate($row[13] ?? null),  // Data di registrazione
            'location_code' => $this->cleanString($row[14] ?? ''),  // Cod. ubicazione
            'printed_copies' => $this->parseInteger($row[15] ?? 0),  // Copie stampate
            'document_date' => $this->parseDate($row[16] ?? null),  // Data documento
            'pay_to_address' => $this->cleanString($row[17] ?? ''),  // Pagare a - Indirizzo
            'pay_to_city' => $this->cleanString($row[18] ?? ''),  // Pagare a - Città
            'supplier_category' => $this->cleanString($row[19] ?? ''),  // Cat. reg. fornitore
            'exchange_rate' => $this->parseAmount($row[20] ?? 0),  // Fattore valuta
            'vat_number' => $this->cleanString($row[21] ?? ''),  // Partita IVA
            'fiscal_code' => $this->cleanString($row[22] ?? ''),  // Codice fiscale
            'document_type' => $this->cleanString($row[23] ?? ''),  // Tipo Documento Fattura
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Check if the first row is a header row
     */
    protected function isHeaderRow(array $row): bool
    {
        // Check if first row contains typical header values
        $headerIndicators = ['numero', 'data', 'fornitore', 'importo', 'description'];
        $firstRow = array_map('strtolower', array_map('trim', $row));

        foreach ($headerIndicators as $indicator) {
            if (in_array($indicator, $firstRow)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if row is empty
     */
    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (! empty($cell) && trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Clean string value
     */
    protected function cleanString($value): string
    {
        if (is_null($value)) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', (string) $value));
    }

    /**
     * Parse date from Excel format
     */
    protected function parseDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Handle Excel serial date format
        if (is_numeric($value)) {
            $date = Date::excelToDateTimeObject($value);

            return $date->format('Y-m-d');
        }

        // Handle string date format
        try {
            $date = new \DateTime($value);

            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('Could not parse date', ['value' => $value, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Parse amount from Excel
     */
    protected function parseAmount($value): float
    {
        if (empty($value)) {
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

    /**
     * Parse boolean from Excel
     */
    protected function parseBoolean($value): ?bool
    {
        if (empty($value)) {
            return null;
        }

        // Handle Excel TRUE/FALSE strings
        $value = strtoupper(trim($value));

        if ($value === 'TRUE' || $value === '=TRUE()' || $value === 'VERO') {
            return true;
        } elseif ($value === 'FALSE' || $value === '=FALSE()' || $value === 'FALSO') {
            return false;
        }

        return null;
    }

    /**
     * Parse integer from Excel
     */
    protected function parseInteger($value): int
    {
        if (empty($value)) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * Get import results
     */
    public function getResults(): array
    {
        return $this->importResults;
    }
}
