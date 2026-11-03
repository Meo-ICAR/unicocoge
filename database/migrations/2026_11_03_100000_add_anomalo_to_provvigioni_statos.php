<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Stato «Anomalo»: la provvigione che il dettaglio della banca non conferma (importo diverso o pratica di un'altra banca). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provvigioni_statos')) {
            DB::table('provvigioni_statos')->insertOrIgnore(['stato' => 'Anomalo']);
        }
    }

    public function down(): void
    {
        //
    }
};
