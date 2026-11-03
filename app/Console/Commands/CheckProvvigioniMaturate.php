<?php

namespace App\Console\Commands;

use App\Enums\Severity;
use App\Models\Fornitore;
use App\Models\Provvigione;
use App\Services\CheckStatus;
use Illuminate\Support\Collection;

/**
 * Provvigioni maturate: pratica perfezionata con provvigione ancora «Inserito», cioè non in un proforma e non esclusa. Il valore è
 * il numero, il dettaglio il riepilogo per beneficiario e l'elenco completo viaggia come CSV allegato. Sempre `regular` se ce ne sono:
 * i destinatari li sceglie il passo del processo.
 */
class CheckProvvigioniMaturate extends StatusCheckCommand
{
    protected $signature = 'provvigioni:check-maturate';

    protected $description = 'Provvigioni maturate non ancora in un proforma né escluse (valore: numero; allegato: elenco CSV)';

    public function checkStatus(): CheckStatus
    {
        return $this->statusOf($this->maturate());
    }

    /**
     * Se l'email è di un fornitore (agente) il dettaglio riguarda solo le sue provvigioni; per chiunque altro resta l'elenco completo.
     */
    public function checkStatusForRecipient(string $email): CheckStatus
    {
        $piva = Fornitore::query()->where('email', $email)->whereNotNull('piva')->value('piva');

        if (blank($piva)) {
            return $this->checkStatus();
        }

        return $this->statusOf($this->maturate()->where('piva', $piva)->values());
    }

    /**
     * @return Collection<int, Provvigione>
     */
    private function maturate(): Collection
    {
        return Provvigione::query()
            ->where('status_compenso', 'Pratica perfezionata')
            ->where('stato', 'Inserito')
            ->orderBy('tipo')->orderBy('denominazione_riferimento')->orderBy('id_pratica')
            ->get();
    }

    /**
     * @param  Collection<int, Provvigione>  $maturate
     */
    private function statusOf(Collection $maturate): CheckStatus
    {
        if ($maturate->isEmpty()) {
            return new CheckStatus(0, Severity::Ok, 'Nessuna provvigione maturata da lavorare.');
        }

        return new CheckStatus(
            $maturate->count(),
            Severity::Regular,
            $this->summary($maturate),
            ['filename' => 'provvigioni-maturate-'.now()->format('Y-m').'.csv', 'content' => $this->csv($maturate)],
        );
    }

    /**
     * @param  Collection<int, Provvigione>  $maturate
     */
    private function summary(Collection $maturate): string
    {
        $lines = $maturate->groupBy(fn (Provvigione $p): string => "{$p->tipo} — {$p->denominazione_riferimento}")
            ->map(fn (Collection $group, string $beneficiary): string => sprintf('- %s: %d provvigioni, € %s', $beneficiary, $group->count(), number_format((float) $group->sum('importo'), 2, ',', '.')));

        return "{$maturate->count()} provvigioni maturate, totale € ".number_format((float) $maturate->sum('importo'), 2, ',', '.').".\n".$lines->implode("\n");
    }

    /**
     * @param  Collection<int, Provvigione>  $maturate
     */
    private function csv(Collection $maturate): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Provvigione', 'Pratica', 'Tipo', 'Beneficiario', 'Descrizione', 'Importo'], ';');

        foreach ($maturate as $provvigione) {
            fputcsv($stream, [
                $provvigione->id, $provvigione->id_pratica, $provvigione->tipo, $provvigione->denominazione_riferimento,
                $provvigione->descrizione, number_format((float) $provvigione->importo, 2, ',', ''),
            ], ';');
        }

        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
