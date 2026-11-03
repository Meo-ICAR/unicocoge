<?php

namespace App\Filament\Resources\Provvigiones\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ProvvigioneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Provvigione')
                    ->tabs([
                        Tab::make('Anagrafica')
                            ->schema([
                                TextInput::make('id')
                                    ->label('ID Provvigione')
                                    ->disabled(),
                                TextInput::make('id_pratica')
                                    ->disabled()
                                    ->required(),
                                TextInput::make('descrizione')
                                    ->disabled(),
                                TextInput::make('tipo')
                                    ->disabled(),
                                TextInput::make('segnalatore')
                                    ->disabled(),
                                TextInput::make('istituto_finanziario')
                                    ->disabled(),
                                TextInput::make('piva'),
                                TextInput::make('cf')->disabled(),
                                TextInput::make('fonte')->disabled(),
                                TextInput::make('tipo_pratica')->disabled(),
                                TextInput::make('prodotto')->disabled(),
                                Textarea::make('note')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Tab::make('Importi')
                            ->schema([
                                TextInput::make('importo')
                                    ->disabled()
                                    ->numeric()
                                    ->required(),
                                TextInput::make('quota'),
                            ])
                            ->columns(2),
                        Tab::make('Date')
                            ->schema([
                                DatePicker::make('data_inserimento_compenso')
                                    ->label('Data Inserimento')
                                    ->disabled()
                                    ->required(),
                                DatePicker::make('data_pagamento'),
                                DatePicker::make('data_status'),
                                DatePicker::make('data_inserimento_pratica')->disabled(),
                                DatePicker::make('data_stipula'),
                                DatePicker::make('data_status_pratica'),
                            ])
                            ->columns(2),
                        Tab::make('Documenti')
                            ->schema([
                                TextInput::make('n_fattura')
                                    ->disabled()
                                    ->label('Numero Fattura'),
                                DatePicker::make('data_fattura')->disabled(),
                                TextInput::make('invoice_number')
                                    ->disabled()
                                    ->label('Numero Fattura'),
                            ])
                            ->columns(2),
                        Tab::make('Stato')
                            ->schema([
                                Select::make('stato')
                                    ->options([
                                        'Inserito' => 'Inserito',
                                        'Sospeso' => 'Sospeso',
                                        'Proforma' => 'Proforma',
                                        'Pagato' => 'Pagato',
                                        'Annullato' => 'Annullato',
                                        'Escluso' => 'Escluso',
                                        'Fatturato' => 'Fatturato',
                                        'Stornato' => 'Stornato',
                                        'Anomalo' => 'Anomalo',
                                    ]),
                                TextInput::make('status_compenso')->disabled(),
                                TextInput::make('status_pratica')->disabled(),
                                TextInput::make('status_pagamento')->disabled(),
                                TextInput::make('macrostatus')->disabled(),
                                Toggle::make('annullato'),
                                Toggle::make('coordinamento'),
                            ])
                            ->columns(2),
                        Tab::make('Altro')
                            ->schema([
                                Group::make()
                                    ->relationship('pratica')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('cognome_cliente')
                                            ->label('Cognome Cliente')
                                            ->disabled(),
                                        TextInput::make('nome_cliente')
                                            ->label('Nome Cliente')
                                            ->disabled(),
                                    ]),
                                TextInput::make('denominazione_riferimento')->disabled(),
                                TextInput::make('entrata_uscita')->disabled(),
                                TextInput::make('proforma_id')
                                    ->disabled()
                                    ->numeric(),
                            ])
                            ->columns(2),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
