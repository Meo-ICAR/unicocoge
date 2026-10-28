<?php

namespace App\Filament\Resources\Provvigiones\Tables;

use App\Filament\Actions\CaricaProformaBancaAction;
use App\Filament\Actions\QuickExcelExportAction;
use App\Filament\Exports\DynamicGroupExport;
use App\Filament\Resources\Praticas\PraticaResource;
use App\Models\Cliente;
use App\Models\Compenso;
use App\Models\Proforma;
use App\Models\Provvigione;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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

class AttiveTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginated([25, 50, 100, 1000])
            ->query(Provvigione::query()
                ->where('entrata_uscita', 'Entrata')
                ->whereNot('importo', 0))
            //  ->where('descrizione', 'not like', '%liente%'))
            ->reorderableColumns()
            ->selectable()
            ->checkIfRecordIsSelectableUsing(
                fn (Model $record): bool => $record->stato === 'Inserito'
            )
            ->headerActions([
                CaricaProformaBancaAction::make(),
                ExportAction::make()
                    ->exports([
                        DynamicGroupExport::make()
                            ->groupBy('Istituto Finanziario')  // Campo per il raggruppamento
                            ->sumColumns(['Provvigione']),  // Campi da sommare
                    ])
                    ->label('Excel')
                    ->color('success'),
                QuickExcelExportAction::make(),
                BulkAction::make('emetti')
                    ->label('Associa a Proforma')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->accessSelectedRecords()
                    // chiede data emissione proforma
                    // crea o trova proforma con fornitore_id = $record->istituto_finanziario
                    // associa provvigioni a proforma
                    ->form([
                        DatePicker::make('data_emissione')
                            ->label('Data Emissione Proforma')
                            ->default(now())
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(fn ($state, callable $set) => $set('data_emissione', $state)),
                    ])
                    ->action(function (Collection $records, array $data) {
                        $dataEmissione = $data['data_emissione'];

                        Proforma::associaProvvigioni($records, $dataEmissione);

                        // Show success notification with count and date
                        Notification::make()
                            ->title(count($records).' provvigioni abbinate a proforma')
                            ->body('Data emissione: '.Carbon::parse($dataEmissione)->format('d/m/Y'))
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
                            ->title(count($records).' provvigioni annullate')
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
                TextColumn::make('istituto_finanziario')
                    ->label('Istituto Finanziario')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('importo')
                    ->label('Provvigione')
                    ->money('EUR')  // Forza Euro e formato italiano
                    ->alignEnd()
                    ->summarize(Sum::make()->money('EUR')->label('')->query(fn (Builderq $query) => $query->where('stato', 'Inserito')))
                    ->sortable(),
                TextColumn::make('data_status')
                    ->label('Perfezionata il')
                    ->date()
                    ->sortable(),
                TextColumn::make('data_fattura')
                    ->label('Fatturato il')
                    ->date()
                    ->sortable(),
                TextColumn::make('pratica.cognome_cliente')
                    ->label('Cognome Cliente')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('pratica.nome_cliente')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('pratica.tipoprodotto.tipo_prodotto')
                    ->label('Tipo prodotto')
                    ->searchable(),
                TextColumn::make('id_pratica')
                    ->label('Pratica')
                    ->color('info')
                    ->url(fn ($record) => PraticaResource::getUrl('view', ['record' => $record->id_pratica]))
                    ->openUrlInNewTab()
                    ->searchable(),
                TextColumn::make('descrizione'),
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
                TextColumn::make('status_compenso'),
            ])
            ->filters([
                /*
                 * Filter::make('data_status')
                 *     ->form([
                 *         DatePicker::make('data_status')
                 *             ->label('Provvigioni maturate fino al')
                 *         //  ->default(now()->subMonth()->endOfMonth()),
                 *     ]),
                 */
                SelectFilter::make('cliente_id')
                    ->label('Filtra per Istituto')
                    ->relationship('cliente', 'name')  // 'cliente' è il nome del metodo nel Model, 'nome_societa' la colonna da visualizzare
                    ->searchable()  // Abilita l'autocomplete (Ajax)
                    ->preload()  // Opzionale: carica i primi risultati all'apertura (utile se i clienti non sono decine di migliaia)
                    //   ->options(function () {
                    //       return Cliente::orderBy('name')->pluck('name', 'id');
                    //    })
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->name}"),  // Opzionale: per personalizzare cosa vedi nel dropdown
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
                SelectFilter::make('descrizione')
                    ->options([
                        'COMPENSO' => 'COMPENSO',
                        'Compenso  ()' => 'Compenso  ()',
                        'Compenso  (50)' => 'Compenso  (50)',
                        'COMPENSO (50)' => 'COMPENSO (50)',
                        'Compenso BROKERAGGIO' => 'Compenso BROKERAGGIO',
                        'Compenso da Cliente' => 'Compenso da Cliente',
                        'Compenso da Istituto' => 'Compenso da Istituto',
                        'Compenso da Istituto I tranche' => 'Compenso da Istituto I tranche',
                        'Compenso da istituto II Tranche' => 'Compenso da istituto II Tranche',
                        'Compenso da istituto III tranche' => 'Compenso da istituto III tranche',
                        'compenso gas' => 'compenso gas',
                        'compenso II tranche' => 'compenso II tranche',
                        'compenso internet' => 'compenso internet',
                        'compenso luce' => 'compenso luce',
                        'COMPENSO PREMIO' => 'COMPENSO PREMIO',
                        'Compenso Premio (1) da Istituto' => 'Compenso Premio (1) da Istituto',
                        'Compenso Premio (2,5) da Istituto' => 'Compenso Premio (2,5) da Istituto',
                        'Compenso Premio (3) da Istituto' => 'Compenso Premio (3) da Istituto',
                        'POLIZZA BANCA' => 'POLIZZA BANCA',
                    ])
                    ->multiple()
                    ->placeholder('Tutti i tipi di compenso'),
                SelectFilter::make('status_compenso')
                    ->label('Stato Compenso')
                    ->multiple()
                    ->options(Compenso::all()->pluck('status_compenso', 'status_compenso')),
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
                    //    ->default(now()->startOfMonth()->subDay(1)->format('m'))
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

                        return 'Stato fino a fine '.$dataScelta->translatedFormat('F Y');
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
                    //    ->default(now()->startOfMonth()->subDay(1)->format('m'))
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
                Action::make('forceStatus')
                    ->label('')
                    ->icon('heroicon-o-arrow-uturn-down')
                    ->action(function ($record) {
                        $record->update([
                            'stato' => $record->stato === null ? 'Inserito' : null,
                        ]);
                        Notification::make()
                            ->title('Stato forzato con successo')
                            ->success()
                            ->send();
                    })
                    ->visible(fn ($record): bool => in_array($record->stato, ['Inserito', null]))
                    ->iconButton()
                    ->color('success'),
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
                Group::make('istituto_finanziario')
                    ->label('Istituto')
                    ->collapsible(),  // SOSTITUISCE le vecchie impostazioni di groupingSettings
            ])
            ->defaultGroup('istituto_finanziario');
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
