<?php

namespace App\Filament\Resources\Proformas\RelationManagers;

use App\Filament\Resources\Praticas\PraticaResource;
use App\Filament\Traits\HasRelationPlanAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class ProvvigioniRelationManager extends RelationManager
{
    use HasRelationPlanAccess;

    protected static string $relationship = 'provvigioni';

    protected static ?string $recordTitleAttribute = 'id';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('descrizione')
                    ->required()
                    ->maxLength(255),
                TextInput::make('importo')
                    ->required()
                    ->prefix('€'),
                Select::make('stato')
                    ->options([
                        'Inserito' => 'Inserito',
                        'Inviato' => 'Inviato',
                        'Pagato' => 'Pagato',
                        'Annullato' => 'Annullato',
                    ])
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('descrizione')
            ->columns([
                TextColumn::make('pratica.cognome_cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('pratica.nome_cliente'),
                TextColumn::make('importo')
                    ->money('EUR')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('EUR')->label(''))
                    ->sortable(),
                IconColumn::make('coordinamento')
                    ->boolean()
                    ->sortable()
                    ->trueIcon(Heroicon::OutlinedCheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->falseColor('white')
                    ->label('Coord'),
                TextColumn::make('data_status')
                    ->date()
                    ->sortable(),
                TextColumn::make('istituto_finanziario')
                    ->searchable(),
                TextColumn::make('data_fattura')
                    ->label('Fattura del')
                    ->date()
                    ->sortable(),
                TextColumn::make('id_pratica')
                    ->label('Pratica')
                    ->color('info')
                    ->url(fn ($record) => $record->pratica_id ? PraticaResource::getUrl('view', ['record' => $record->pratica_id]) : null)
                    ->openUrlInNewTab(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Tables\Actions\CreateAction::make(),
            ])
            ->recordActions([
                // ...
                Action::make('toggleStatus')
                    ->label('')
                    ->icon('heroicon-o-trash')
                    ->action(function ($record) {
                        $compenso = $record->proforma->compenso;
                        if ($compenso > $record->importo) {
                            $compenso -= $record->importo;
                            $record->proforma->update([
                                'compenso' => $compenso,
                            ]);
                            $record->update([
                                'stato' => 'Inserito',
                                'proforma_id' => null,
                            ]);
                            Notification::make()
                                ->title('Provvigione rimossa dal proforma')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('ATTENZIONE Rimuovere direttamente il proforma')
                                ->danger()
                                ->send();
                        }
                    })
                    ->iconButton()
                    ->color('primary'),
            ], position: RecordActionsPosition::BeforeColumns);
    }
}
