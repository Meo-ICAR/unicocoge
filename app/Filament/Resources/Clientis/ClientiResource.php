<?php

namespace App\Filament\Resources\Clientis;

use App\Filament\Resources\Clientis\Pages\CreateClienti;
use App\Filament\Resources\Clientis\Pages\EditClienti;
use App\Filament\Resources\Clientis\Pages\ListClientis;
use App\Filament\Resources\Clientis\Schemas\ClientiForm;
use App\Filament\Resources\Clientis\Tables\ClientisTable;
use App\Filament\Traits\HasPlanAccess;
use App\Models\Clienti;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Unico\Core\Filament\RelationManagers\DocumentsRelationManager;
use UnitEnum;

class ClientiResource extends Resource
{
    use HasPlanAccess;

    protected static ?string $model = Clienti::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Istituti';

    protected static ?string $modelLabel = 'Istituti';

    protected static ?string $pluralModelLabel = 'Istituti';

    // protected static UnitEnum|string|null $navigationGroup = 'Archivi';

    protected static ?int $navigationSort = 8;

    protected static UnitEnum|string|null $navigationGroup = 'Anagrafiche';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ClientiForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientisTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $n =
            Clienti::activeWithoutPiva()->count();
        if ($n > 0) {
            return $n;
        }

        return null;
    }

    public static function getNavigationBadgeTooltip(): Htmlable|string|null
    {
        return 'Istituti senza partita IVA';
    }

    public static function getNavigationBadgeAction(): ?Action
    {
        // filters[piva][value]=0
        return Action::make('filter_no_piva')
            ->label('Filtra senza partita IVA')
            ->icon('heroicon-o-funnel')
            ->url(fn () => static::getUrl('index', [
                'filters' => [
                    'piva' => [
                        'value' => 0,
                    ],
                ],
            ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClientis::route('/'),
            'create' => CreateClienti::route('/create'),
            'edit' => EditClienti::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
