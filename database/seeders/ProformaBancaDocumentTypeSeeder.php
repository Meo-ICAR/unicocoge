<?php

namespace Database\Seeders;

use App\Services\ProformaBanca\ImportaProformaBanca;
use Illuminate\Database\Seeder;
use Unico\Core\Models\DocumentType;

/** Tipo documento del dettaglio pratiche inviato dalla banca. Ripetibile: se il tipo esiste non lo tocca. */
class ProformaBancaDocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        DocumentType::query()->firstOrCreate(['code' => ImportaProformaBanca::TIPO_DOCUMENTO], [
            'name' => 'Proforma banca',
            'description' => 'Dettaglio delle pratiche e delle provvigioni inviato dalla banca (CSV, Excel, immagine o PDF).',
            'codegroup' => 'BANCA',
        ]);
    }
}
