<?php

namespace App\Console\Commands;

use App\Services\CheckStatus;
use Illuminate\Console\Command;

/**
 * Base dei comandi di controllo: calcolano soltanto lo stato (valore + severity) e non inviano
 * nulla. UnicoBPM li interroga via GET /api/checks/{comando} (CheckStatusApiController) e,
 * in base alla severity e alla RACI, decide chi avvisare con quale email.
 */
abstract class StatusCheckCommand extends Command
{
    abstract public function checkStatus(): CheckStatus;

    /**
     * Stato per un destinatario preciso (email): di default coincide con quello generale; i controlli
     * con un elenco per beneficiario lo restringono ai suoi dati.
     */
    public function checkStatusForRecipient(string $email): CheckStatus
    {
        return $this->checkStatus();
    }

    public function handle(): int
    {
        $status = $this->checkStatus();

        $this->line("Valore: {$status->value} — severity: {$status->severity->value}");

        if ($status->details) {
            $this->line($status->details);
        }

        return self::SUCCESS;
    }
}
