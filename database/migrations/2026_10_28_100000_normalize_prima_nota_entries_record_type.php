<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le voci importate dallo storico hanno `record_type` con l'alias ("proforma")
 * invece della classe: la relazione morph non lo risolve e la lista va in 500.
 */
return new class extends Migration
{
    private const ALIASES = [
        'proforma' => 'App\Models\Proforma',
        'provvigione' => 'App\Models\Provvigione',
        'pratica' => 'App\Models\Pratica',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('prima_nota_entries') || ! Schema::hasColumn('prima_nota_entries', 'record_type')) {
            return;
        }

        foreach (self::ALIASES as $alias => $class) {
            DB::table('prima_nota_entries')->where('record_type', $alias)->update(['record_type' => $class]);
        }
    }

    public function down(): void
    {
    }
};
