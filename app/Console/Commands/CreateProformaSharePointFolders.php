<?php

namespace App\Console\Commands;

use App\Services\ProformaBanca\CartelleProformaSharePoint;
use Illuminate\Console\Command;
use Throwable;

class CreateProformaSharePointFolders extends Command
{
    protected $signature = 'proforma:sharepoint-cartelle
                            {--dry-run : Elenca le cartelle PROFORMA / <banca> che si creerebbero, senza chiamare SharePoint}';

    protected $description = 'Crea su SharePoint la cartella PROFORMA / <banca> per ogni cliente (istituto) attivo e non fittizio';

    public function handle(CartelleProformaSharePoint $cartelle): int
    {
        $banche = $cartelle->banche();

        if ($this->option('dry-run')) {
            $banche->each(fn ($banca) => $this->line(implode(' / ', $cartelle->cartelleDi((string) $banca->name))));
            $this->info("{$banche->count()} cartelle (dry-run).");

            return self::SUCCESS;
        }

        if (! $cartelle->configurato()) {
            $this->error('SharePoint non configurato: imposta SHARE_TENANT_ID, SHARE_CLIENT_ID, SHARE_SECRET_ID e SHARE_DRIVE_ID nel file .env.');

            return self::FAILURE;
        }

        try {
            $esito = $cartelle->creaPerBanche($banche);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(count($esito['create']).' cartelle create, '.count($esito['esistenti']).' già presenti.');

        return self::SUCCESS;
    }
}
