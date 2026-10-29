<?php

namespace App\Filament\Actions;

use App\Filament\Resources\Clientis\ClientiResource;
use App\Services\ProformaBanca\ImportaProformaBanca;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/** Carica il dettaglio inviato da una banca, lo abbina alle provvigioni attive e, se gli importi tornano, crea il proforma. */
class CaricaProformaBancaAction
{
    public static function make(string $name = 'caricaProformaBanca'): Action
    {
        return Action::make($name)
            ->label('Carica Proforma Banca')
            ->icon('heroicon-o-arrow-up-tray')
            ->modalDescription('Carica il dettaglio inviato dalla banca (CSV, Excel, immagine o PDF). Se gli importi coincidono con le provvigioni attive si crea il proforma, altrimenti le difformità restano nel documento della banca.')
            ->schema([
                FileUpload::make('file')
                    ->label('Dettaglio della banca')
                    ->acceptedFileTypes([
                        'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.oasis.opendocument.spreadsheet', 'application/pdf', 'image/*',
                    ])
                    ->storeFiles(false)
                    ->required(),
                DatePicker::make('data_emissione')
                    ->label('Data Emissione Proforma')
                    ->default(now())
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var TemporaryUploadedFile $file */
                $file = $data['file'];

                try {
                    $esito = app(ImportaProformaBanca::class)->importa(
                        $file->get(),
                        $file->getClientOriginalName(),
                        (string) $file->getMimeType(),
                        Carbon::parse($data['data_emissione'])->toDateString(),
                    );
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title('Proforma banca non importato')->body($e->getMessage())->persistent()->send();

                    return;
                }

                $apri = Action::make('apri')
                    ->label('Apri il documento')
                    ->url(ClientiResource::getUrl('edit', ['record' => $esito->documento->documentable_id, 'activeRelationManager' => 0]));

                if ($esito->ok()) {
                    Notification::make()->success()
                        ->title('Proforma banca OK')
                        ->body("{$esito->banca}: {$esito->abbinate} provvigioni abbinate, proforma creato.")
                        ->actions([$apri])->persistent()->send();

                    return;
                }

                $anomalie = collect($esito->anomalie())->map(fn (int $n, string $tipo): string => "{$tipo}: {$n}")->implode("\n");

                Notification::make()->warning()
                    ->title('Proforma banca: '.count($esito->difformita).' difformità, proforma non creato')
                    ->body("Per le difformità il proforma non è stato creato e nessuna provvigione è stata associata. I codici pratica banca sono stati aggiornati. Rimuovi prima le difformità e ricarica il file. Il dettaglio per riga è nel documento.\n\n".$anomalie)
                    ->actions([$apri])->persistent()->send();
            });
    }
}
