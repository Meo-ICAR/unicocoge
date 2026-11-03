<?php

namespace App\Console\Commands;

use App\Models\Fornitore;
use App\Models\Proforma;
use App\Models\Provvigione;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GeneratePendingProformas extends Command
{
    protected $signature = 'proformas:generate-pending
                            {--solo-attivi=1 : Solo produttori attivi (1, predefinito); 0 per includere anche i non attivi}
                            {--dry-run : Elenca i proforma che si creerebbero o aggiornerebbero, senza scrivere}';

    protected $description = 'Per ogni produttore con provvigioni maturate (tipo Agente, non di coordinamento, stato «Inserito») crea il proforma se non esiste e vi associa le provvigioni';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyActive = filter_var($this->option('solo-attivi'), FILTER_VALIDATE_BOOLEAN);

        $maturate = Provvigione::query()
            ->where('tipo', 'Agente')
            ->where('coordinamento', false)
            ->where('status_compenso', 'Pratica perfezionata')
            ->where('stato', 'Inserito')
            ->whereNull('proforma_id')
            ->orderBy('id')
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($maturate->groupBy(fn (Provvigione $p): string => str_replace(' ', '', (string) $p->piva)) as $piva => $group) {
            $label = $group->first()->denominazione_riferimento ?: "P.IVA {$piva}";

            if (strlen($piva) !== 11 && strlen($piva) !== 16) {
                $this->warn("{$label}: partita IVA assente o non valida, {$group->count()} provvigioni non associate.");
                $skipped += $group->count();

                continue;
            }

            $fornitore = Fornitore::withTrashed()->whereRaw("REPLACE(piva, ' ', '') = ?", [$piva])->first();

            if ($fornitore === null) {
                $this->warn("{$label}: produttore non trovato, {$group->count()} provvigioni non associate.");
                $skipped += $group->count();

                continue;
            }

            if ($onlyActive && ! $fornitore->is_active) {
                $this->warn("{$label}: produttore non attivo, {$group->count()} provvigioni non associate.");
                $skipped += $group->count();

                continue;
            }

            if ($dryRun) {
                $this->line($this->describe($label, $fornitore, $group));
                $created += $group->count();

                continue;
            }

            DB::transaction(function () use ($piva, $group, &$created): void {
                foreach ($group as $provvigione) {
                    $proformaId = Proforma::findOrCreateByPiva($piva, (float) $provvigione->importo, false);
                    $provvigione->update(['stato' => 'Proforma', 'proforma_id' => $proformaId]);
                    $created++;
                }
            });

            $this->info("{$label}: {$group->count()} provvigioni associate al proforma.");
        }

        $this->info(($dryRun ? 'Da associare: ' : 'Associate: ')."{$created} provvigioni, non associate: {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Provvigione>  $group
     */
    private function describe(string $label, Fornitore $fornitore, Collection $group): string
    {
        $existing = Proforma::query()->where('fornitori_id', $fornitore->id)->where('stato', 'Inserito')->exists();

        return sprintf(
            '%s: %d provvigioni, € %s — %s.',
            $label,
            $group->count(),
            number_format((float) $group->sum('importo'), 2, ',', '.'),
            $existing ? 'si aggiunge al proforma «Inserito» esistente' : 'si crea un nuovo proforma',
        );
    }
}
