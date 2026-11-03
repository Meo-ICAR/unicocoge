<?php

namespace App\Services\ProformaBanca;

use App\Models\Clienti;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;
use Unico\Core\Models\Document;
use Unico\Core\SharePoint\DocumentSharePointSync;
use Unico\Core\SharePoint\GraphTransport;
use Unico\Core\SharePoint\SharePointUploader;

/**
 * Cartelle SharePoint dei dettagli delle banche: PROFORMA / <nome del cliente>. Qui si creano per le banche attive e non fittizie e vi si
 * porta il documento caricato dall'azione «Carica Proforma Banca».
 */
class CartelleProformaSharePoint extends DocumentSharePointSync
{
    public function __construct(private SharePointUploader $caricatore, private GraphTransport $graph)
    {
        parent::__construct($caricatore);
    }

    public function configurato(): bool
    {
        return $this->caricatore->isConfigured();
    }

    /** @return Collection<int, Clienti> */
    public function banche(): Collection
    {
        return Clienti::query()->where('is_active', true)->where('is_dummy', false)->orderBy('name')->get();
    }

    /** @return list<string> PROFORMA e il nome della banca, come lo vuole SharePoint */
    public function cartelleDi(string $banca): array
    {
        return [$this->caricatore->sanitize((string) config('services.sharepoint_proforma.root', 'PROFORMA')), $this->caricatore->sanitize($banca)];
    }

    /** Per i documenti delle banche la cartella è PROFORMA / <banca> invece di quella predefinita del core. */
    public function folders(Document $document): array
    {
        return $this->cartelleDi((string) ($document->documentable?->name ?? $document->documentable_id));
    }

    /**
     * Crea PROFORMA e una cartella per ogni banca; quelle che esistono già restano (Graph risponde 409).
     *
     * @param  Collection<int, Clienti>  $banche
     * @return array{create: list<string>, esistenti: list<string>}
     */
    public function creaPerBanche(Collection $banche): array
    {
        $esito = ['create' => [], 'esistenti' => []];
        $radice = $this->cartelleDi('x')[0];

        $this->creaCartella($radice, null);

        foreach ($banche as $banca) {
            $nome = $this->caricatore->sanitize((string) $banca->name);
            $creata = $this->creaCartella($nome, $radice);
            $esito[$creata ? 'create' : 'esistenti'][] = $nome;
        }

        return $esito;
    }

    /** Porta su SharePoint il documento della banca senza far fallire l'import: l'errore resta nei metadati del documento. */
    public function caricaDocumento(Document $documento): bool
    {
        $media = $documento->currentMedia();

        if ($media === null || ! $this->configurato()) {
            return false;
        }

        try {
            $this->upload($documento, $media->getPath(), (string) $media->file_name);
        } catch (Throwable $e) {
            $this->markFailed($documento, $e);

            return false;
        }

        return true;
    }

    /** @return bool true se l'ha creata, false se c'era già */
    private function creaCartella(string $nome, ?string $dentro): bool
    {
        $drive = config('unico-core.sharepoint.drive_id');
        $base = "https://graph.microsoft.com/v1.0/drives/{$drive}/root".($dentro === null ? '' : ':/'.rawurlencode($dentro).':').'/children';

        $risposta = $this->graph->post($base, [
            'name' => $nome,
            'folder' => new \stdClass,
            '@microsoft.graph.conflictBehavior' => 'fail',
        ]);

        return match (true) {
            $risposta['status'] === 409 => false,
            $risposta['status'] >= 400 => throw new RuntimeException("Cartella «{$nome}» non creata (HTTP {$risposta['status']})"),
            default => true,
        };
    }
}
