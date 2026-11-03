<?php

namespace Database\Seeders;

use App\Models\ProvvigioniStato;
use Illuminate\Database\Seeder;

class ProvvigioniStatoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Stati possibili di 'provvigioni.stato' (FK verso questa tabella).
     */
    public function run(): void
    {
        $stati = ['', 'Anomalo', 'Annullato', 'Coordinamento', 'Escluso', 'Fatturato', 'Inserito', 'Pagato', 'Proforma', 'Sospeso', 'Stornato'];

        foreach ($stati as $stato) {
            ProvvigioniStato::updateOrCreate(['stato' => $stato]);
        }
    }
}
