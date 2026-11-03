<?php

namespace App\Console\Commands;

use App\Models\Proforma;
use Illuminate\Console\Command;

class SendPendingProformas extends Command
{
    protected $signature = 'proformas:send-pending
                            {--cc= : Indirizzi in copia conoscenza, separati da virgola}
                            {--genera : Prima crea i proforma dalle provvigioni maturate dei produttori (proformas:generate-pending)}
                            {--dry-run : Elenca i proforma che partirebbero, senza inviare}';

    protected $description = 'Invia ai produttori i proforma in stato «Inserito» non ancora spediti (copia conoscenza opzionale)';

    public function handle(): int
    {
        if ($this->option('genera')) {
            $this->call('proformas:generate-pending', ['--dry-run' => (bool) $this->option('dry-run')]);
        }

        $cc = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('cc')))));
        $sent = 0;
        $failed = 0;

        $proformas = Proforma::query()
            ->where('stato', 'Inserito')
            ->whereNull('sended_at')
            ->where('compenso', '!=', 0)
            ->with('fornitore')
            ->orderBy('id')
            ->get();

        foreach ($proformas as $proforma) {
            $label = "Proforma #{$proforma->id} ({$proforma->fornitore?->name})";

            if (blank($proforma->fornitore?->email)) {
                $this->warn("{$label}: fornitore senza email, non inviato.");
                $failed++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("{$label}: partirebbe a {$proforma->fornitore->email}.");

                continue;
            }

            if ($proforma->inviaEmail(false, $cc)) {
                $sent++;
                $this->info("{$label}: inviato a {$proforma->fornitore->email}.");
            } else {
                $failed++;
                $this->error("{$label}: invio fallito (vedi log).");
            }
        }

        $this->info("Proforma inviati: {$sent}, non inviati: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
