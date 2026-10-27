<?php

namespace App\Filament\Resources\Provvigiones\Tables;

use App\Filament\Actions\QuickExcelExportAction;
use App\Filament\Exports\DynamicGroupExport;
use App\Filament\Resources\Praticas\PraticaResource;
use App\Filament\Resources\Provvigiones\Pages\ListProvvigiones;
use App\Jobs\ImportaDaMediafacile;
use App\Models\Compenso;
use App\Models\Proforma;
use App\Models\Provvigione;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;  // ← Import corretto
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as Builderq;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;

class ProvvigionesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginated([25, 50, 100, 1000])
            ->query(Provvigione::query()
                ->where('entrata_uscita', 'Uscita')
                ->whereNot('importo', 0)
                //  ->whereNot('annullato', 1))
            )
            ->reorderableColumns()
            ->selectable()
            ->checkIfRecordIsSelectableUsing(
                fn (Model $record): bool => $record->stato === 'Inserito' && $record->piva != null && ((strlen($record->piva) == 11) || (strlen($record->piva) == 16))
            )
            ->headerActions([
                Action::make('importaDaMediafacile')
                    ->label('Importa da Mediafacile')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->requiresConfirmation()
                    ->modalDescription('Importa pratiche e provvigioni degli ultimi '.ImportaDaMediafacile::GIORNI.' giorni. Al termine arriva la notifica con l\'esito.')
                    ->visible(fn (): bool => ListProvvigiones::puoImportare(auth()->user()))
                    ->action(function (): void {
                        ImportaDaMediafacile::dispatch();

                        Notification::make()->title('Importazione avviata')->success()->send();
                    }),
                ExportAction::make()
                    ->exports([
                        DynamicGroupExport::make()
                            ->groupBy('Produttore')  // Campo per il raggruppamento
                            ->sumColumns(['Provvigione']),  // Campi da sommare
                    ])
                    ->label('Excel')
                    ->color('success'),
                QuickExcelExportAction::make(),
                BulkAction::make('emetti')
                    ->label('Emetti Proforma')
                    ->color('success')
                    ->requiresConfirmation()
                    ->accessSelectedRecords()
                    ->action(function (Collection $records) {
                        // Process each record with a visible loop
                        $records->each(function ($record) {
                            $email = $record->email;
                            $piva = $record->piva;
                            //     \Log::info('Processing record: ' . $email . '-' . $piva);
                            if (($piva != null) && ((strlen($piva) == 11) || (strlen($piva) == 16))) {
                                $proformaId = Proforma::findOrCreateByPiva($piva, $record->importo, $record->coordinamento);
                                $record->update([
                                    'stato' => 'Proforma',
                                    'proforma_id' => $proformaId,
                                ]);
                            } else {
                                $reason = ($piva == null) ? 'senza partita iva' : 'con partita iva non valida (lunghezza: '.strlen($piva).')';
                                Notification::make()
                                    ->title('ATTENZIONE Provvigione di produttore '.$reason.' '.$record->id.' '.$record->denominazione_riferimento
                                        .' '.$record->pratica->cognome_cliente.' '.$record->pratica->nome_cliente.' '.$record->pratica->id_pratica.' proforma non emesso')
                                    ->danger()
                                    ->send();
                            }
                        });

                        // Show success notification with count
                        Notification::make()
                            ->title(count($records).' provvigioni abbinate a proforma')
                            ->success()
                            ->send();
                    }),
                BulkAction::make('forza')
                    ->label('Annulla Provvigioni')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->accessSelectedRecords()
                    ->action(function (Collection $records) {
                        // Process each record with a visible loop
                        $records->each(function ($record) {
                            $record->update([
                                'stato' => 'Annullato',
                            ]);
                        });

                        // Show success notification with count
                        Notification::make()
                            ->title(count($records).' provvigioni abbinate a proforma')
                            ->success()
                            ->send();
                    }),
            ])
            ->columns([
                TextColumn::make('stato')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Inserito' => 'warning',
                        'Sospeso' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('denominazione_riferimento')
                    ->label('Produttore')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('importo')
                    ->label('Provvigione')
                    ->money('EUR')  // Forza Euro e formato italiano
                    ->alignEnd()
                    ->summarize(Sum::make()->money('EUR')->label('')->query(fn (Builderq $query) => $query->whereIn('stato', ['Inserito', 'Pagato'])))
                    ->sortable(),
                IconColumn::make('coordinamento')
                    ->boolean()
                    ->sortable()
                    ->trueIcon(Heroicon::OutlinedCheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->falseColor('white')
                    ->label('Coord'),
                TextColumn::make('data_status')
                    ->label('Perfezionata il')
                    ->date()
                    ->sortable(),
                TextColumn::make('pratica.cognome_cliente')
                    ->label('Cognome Cliente')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('pratica.nome_cliente')
                    ->label('Nome'),
                TextColumn::make('istituto_finanziario')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('pratica.erogated_at')
                    ->label('Erogato')
                    ->date()
                    ->sortable(),
                TextColumn::make('proforma.sended_at')
                    ->label('Proforma')
                    ->date()
                    ->sortable(),
                TextColumn::make('data_fattura')
                    ->label('Fattura del')
                    ->date()
                    ->sortable(),
                TextColumn::make('id_pratica')
                    ->label('Pratica')
                    ->color('info')
                    ->url(fn ($record) => PraticaResource::getUrl('view', ['record' => $record->id_pratica]))
                    ->openUrlInNewTab()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('descrizione'),
                TextColumn::make('status_compenso'),
                TextColumn::make('piva'),
                TextColumn::make('fornitore.email'),
            ])
            ->filters([
                SelectFilter::make('stato')
                    ->options([
                        'Inserito' => 'Inserito',
                        'Sospeso' => 'Sospeso',
                        'Proforma' => 'Proforma',
                        'Pagato' => 'Pagato',
                        'Annullato' => 'Annullato',
                        'Escluso' => 'Escluso',
                        'Fatturato' => 'Fatturato',
                        'Stornato' => 'Stornato',
                    ])
                    ->multiple()
                    ->default(['Inserito', 'Sospeso'])
                    ->placeholder('Tutti gli stati'),
                SelectFilter::make('coordinamento')
                    ->options([
                        1 => 'Si',
                        0 => 'No',
                    ])
                    ->default(in_array(now()->month, [1, 4, 7, 10]) ? null : 0)
                    ->placeholder('Tutti'),
                Filter::make('data_fattura')
                    ->form([
                        Select::make('has_invoice_date')
                            ->label('Abbinato a fattura')
                            ->options([
                                'all' => 'Tutti',
                                'has_date' => 'Si',
                                'no_date' => 'No',
                            ])
                            ->default('all'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $hasPaymentDate = $data['has_invoice_date'] ?? 'all';

                        return match ($hasPaymentDate) {
                            'has_date' => $query->whereNotNull('data_fattura'),
                            'no_date' => $query->whereNull('data_fattura'),
                            default => $query,
                        };
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $hasPaymentDate = $data['has_invoice_date'] ?? 'all';

                        return match ($hasPaymentDate) {
                            'has_date' => 'Abbinato a fattura',
                            'no_date' => 'Non abbinato a fattura',
                            default => null,
                        };
                    }),
                Filter::make('erogated_at')
                    ->form([
                        Select::make('has_erogated_date')
                            ->label('Erogato')
                            ->options([
                                'all' => 'Tutti',
                                'has_date' => 'Si',
                                'no_date' => 'No',
                            ])
                            ->default('all'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $hasErogatedDate = $data['has_erogated_date'] ?? 'all';

                        return match ($hasErogatedDate) {
                            'has_date' => $query->whereNotNull('erogated_at'),
                            'no_date' => $query->whereNull('erogated_at'),
                            default => $query,
                        };
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $hasErogatedDate = $data['has_erogated_date'] ?? 'all';

                        return match ($hasErogatedDate) {
                            'has_date' => 'Con data erogazione',
                            'no_date' => 'Senza data erogazione',
                            default => null,
                        };
                    }),
                SelectFilter::make('status_compenso')
                    ->label('Stato Compenso')
                    ->multiple()
                    ->options(Compenso::all()->pluck('status_compenso', 'status_compenso')),
                Filter::make('denominazione_riferimento')
                    ->form([
                        TextInput::make('denominazione_riferimento')
                            ->label('Produttore')
                            ->placeholder('Cerca per denominazione...'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['denominazione_riferimento'],
                            fn (Builder $query, string $denominazione): Builder => $query->where('denominazione_riferimento', 'like', "{$denominazione}%")
                        );
                    }),
                // test
                Filter::make('istituto_finanziario')
                    ->form([
                        TextInput::make('istituto_finanziario')
                            ->label('Istituto Finanziario')
                            ->placeholder('Cerca per istituto...'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['istituto_finanziario'],
                            fn (Builder $query, string $istituto): Builder => $query->where('istituto_finanziario', 'like', "{$istituto}%")
                        );
                    }),
                SelectFilter::make('mese_status')
                    ->label('Fino al mese')
                    ->options([
                        '01' => 'Gennaio',
                        '02' => 'Febbraio',
                        '03' => 'Marzo',
                        '04' => 'Aprile',
                        '05' => 'Maggio',
                        '06' => 'Giugno',
                        '07' => 'Luglio',
                        '08' => 'Agosto',
                        '09' => 'Settembre',
                        '10' => 'Ottobre',
                        '11' => 'Novembre',
                        '12' => 'Dicembre',
                    ])
                    // Imposta il mese attuale come default (es. "01", "02", ecc.)
                    ->default(now()->startOfMonth()->subDay(1)->format('m'))
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        $meseScelto = (int) $data['value'];
                        $annoRiferimento = now()->year;

                        // Data al 1° del mese scelto nell'anno corrente
                        $dataScelta = Carbon::create($annoRiferimento, $meseScelto, 1);

                        // Se la data calcolata è nel futuro, sottraiamo un anno
                        if ($dataScelta->isFuture()) {
                            $dataScelta->subYear();
                        }

                        // Calcoliamo l'inizio del mese successivo
                        $dataLimite = $dataScelta->copy()->endOfMonth();

                        return $query->where('data_status', '<=', $dataLimite);
                    })
                    // Opzionale: mostra chiaramente nel badge quale anno è stato applicato
                    ->indicateUsing(function (array $data): ?string {
                        if (empty($data['value'])) {
                            return null;
                        }

                        $dataScelta = Carbon::create(now()->year, $data['value'], 1);
                        if ($dataScelta->isFuture()) {
                            $dataScelta->subYear();
                        }

                        return 'Perfezionato fino al '.$dataScelta->translatedFormat('F Y');
                    }),
                SelectFilter::make('mese_erogazione')
                    ->label('Mese erogazione')
                    ->options([
                        '01' => 'Gennaio',
                        '02' => 'Febbraio',
                        '03' => 'Marzo',
                        '04' => 'Aprile',
                        '05' => 'Maggio',
                        '06' => 'Giugno',
                        '07' => 'Luglio',
                        '08' => 'Agosto',
                        '09' => 'Settembre',
                        '10' => 'Ottobre',
                        '11' => 'Novembre',
                        '12' => 'Dicembre',
                    ])
                    // Imposta il mese attuale come default (es. "01", "02", ecc.)
                    //   ->default(now()->startOfMonth()->subDay(1)->format('m'))
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        $meseScelto = (int) $data['value'];
                        $annoRiferimento = now()->year;

                        // Data al 1° del mese scelto nell'anno corrente
                        $dataScelta = Carbon::create($annoRiferimento, $meseScelto, 1);

                        // Se la data calcolata è nel futuro, sottraiamo un anno
                        if ($dataScelta->isFuture()) {
                            $dataScelta->subYear();
                        }

                        // Calcoliamo l'inizio del mese successivo
                        $dataLimite = $dataScelta->copy()->endOfMonth();

                        $dataScelta = $dataScelta->startOfMonth();

                        return $query
                            ->where('erogated_at', '>=', $dataScelta)
                            ->where('erogated_at', '<', $dataLimite);
                    })
                    // Opzionale: mostra chiaramente nel badge quale anno è stato applicato
                    ->indicateUsing(function (array $data): ?string {
                        if (empty($data['value'])) {
                            return null;
                        }

                        $dataScelta = Carbon::create(now()->year, $data['value'], 1);
                        if ($dataScelta->isFuture()) {
                            $dataScelta->subYear();
                        }

                        return 'Erogato nel mese '.$dataScelta->translatedFormat('F Y');
                    }),
                SelectFilter::make('trimestre_erogazione')
                    ->label('Trimestre fatturazione')
                    ->options([
                        '1' => '1° Trimestre (Gen-Mar)',
                        '2' => '2° Trimestre (Apr-Giu)',
                        '3' => '3° Trimestre (Lug-Set)',
                        '4' => '4° Trimestre (Ott-Dic)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        $trimestre = $data['value'];
                        $annoRiferimento = now()->year;

                        switch ($trimestre) {
                            case '1':
                                $dataInizio = Carbon::create($annoRiferimento, 1, 1);
                                $dataFine = Carbon::create($annoRiferimento, 3, 31)->endOfDay();
                                break;
                            case '2':
                                $dataInizio = Carbon::create($annoRiferimento, 4, 1);
                                $dataFine = Carbon::create($annoRiferimento, 6, 30)->endOfDay();
                                break;
                            case '3':
                                $dataInizio = Carbon::create($annoRiferimento, 7, 1);
                                $dataFine = Carbon::create($annoRiferimento, 9, 30)->endOfDay();
                                break;
                            case '4':
                                $dataInizio = Carbon::create($annoRiferimento, 10, 1);
                                $dataFine = Carbon::create($annoRiferimento, 12, 31)->endOfDay();
                                break;
                            default:
                                return $query;
                        }

                        // Se le date sono nel futuro, sottraiamo un anno
                        if ($dataInizio->isFuture()) {
                            $dataInizio->subYear();
                            $dataFine->subYear();
                        }

                        return $query
                            ->where('data_fattura', '>=', $dataInizio)
                            ->where('data_fattura', '<=', $dataFine);
                    })
                    ->indicateUsing(function (array $data): ?string {
                        if (empty($data['value'])) {
                            return null;
                        }

                        $trimestre = $data['value'];
                        $annoRiferimento = now()->year;

                        switch ($trimestre) {
                            case 'Q1':
                                $dataInizio = Carbon::create($annoRiferimento, 1, 1);
                                break;
                            case 'Q2':
                                $dataInizio = Carbon::create($annoRiferimento, 4, 1);
                                break;
                            case 'Q3':
                                $dataInizio = Carbon::create($annoRiferimento, 7, 1);
                                break;
                            case 'Q4':
                                $dataInizio = Carbon::create($annoRiferimento, 10, 1);
                                break;
                            default:
                                return null;
                        }

                        if ($dataInizio->isFuture()) {
                            $dataInizio->subYear();
                        }

                        return 'Fatturato '.$trimestre.' '.$dataInizio->year;
                    }),
                Filter::make('data_fattura')
                    ->form([
                        Select::make('has_invoice_date')
                            ->label('Fatturato')
                            ->options([
                                'all' => 'Tutti',
                                'has_date' => 'Si',
                                'no_date' => 'No',
                            ])
                            ->default('all'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $hasInvoiceDate = $data['has_invoice_date'] ?? 'all';

                        return match ($hasInvoiceDate) {
                            'has_date' => $query->whereNotNull('data_fattura'),
                            'no_date' => $query->whereNull('data_fattura'),
                            default => $query,
                        };
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $hasInvoiceDate = $data['has_invoice_date'] ?? 'all';

                        return match ($hasInvoiceDate) {
                            'has_date' => 'Con data fatturazione',
                            'no_date' => 'Senza data fatturazione',
                            default => null,
                        };
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
                Action::make('toggleStatus')
                    ->label('')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function ($record) {
                        $record->update([
                            'stato' => $record->stato === 'Inserito' ? 'Sospeso' : 'Inserito',
                        ]);
                        Notification::make()
                            ->title('Stato aggiornato con successo')
                            ->success()
                            ->send();
                    })
                    ->visible(fn ($record): bool => in_array($record->stato, ['Inserito', 'Sospeso']))
                    ->iconButton()
                    ->color('primary'),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                BulkActionGroup::make([
                    //   DeleteBulkAction::make(),
                    //   ForceDeleteBulkAction::make(),
                    //   RestoreBulkAction::make(),
                ]),
            ])
            ->groups([
                Group::make('stato')
                    ->label('Stato Pratica')
                    ->collapsible(),  // SOSTITUISCE le vecchie impostazioni di groupingSettings
                Group::make('denominazione_riferimento')
                    ->label('Produttore')
                    ->collapsible(),  // SOSTITUISCE le vecchie impostazioni di groupingSettings
            ])
            ->defaultGroup('denominazione_riferimento');

        /*
         * ->recordGroupActions([
         * Action::make('create_proforma')
         * ->label('Emetti proforma')
         * ->icon('heroicon-o-arrow-down-tray')
         * ->action(function (Group $livewire, array $data, $groupKey) {
         *     // $groupKey contiene il valore del raggruppamento
         *     // Esporta solo record di questo gruppo
         * }),
         *  ])
         */
        // Se vuoi che sia raggruppato di default:
        // ->defaultGroup('denominazione_riferimento');
    }

    protected function getTableListeners(): array
    {
        return [
            'table-row-selected' => 'handleRowSelected',
            'table-row-deselected' => 'handleRowDeselected',
        ];
    }

    public function handleRowSelected($recordId): void
    {
        $record = Model::find($recordId);
        if ($record->stato === 'Inserito') {
            $record->update(['stato' => 'Sospeso']);
        }
    }

    public function handleRowDeselected($recordId): void
    {
        $record = Model::find($recordId);
        $record->update(['stato' => 'Inserito']);
    }
}
