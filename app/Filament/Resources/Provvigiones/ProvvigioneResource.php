<?php

namespace App\Filament\Resources\Provvigiones;

use App\Filament\Resources\Provvigiones\Pages\EditProvvigione;
use App\Filament\Resources\Provvigiones\Pages\ListProvvigiones;
use App\Filament\Resources\Provvigiones\Pages\ListProvvigioniAttive;
use App\Filament\Resources\Provvigiones\Pages\ViewProvvigione;
use App\Filament\Resources\Provvigiones\Schemas\ProvvigioneForm;
use App\Filament\Resources\Provvigiones\Tables\ProvvigionesTable;
use App\Filament\Traits\HasPlanAccess;
use App\Models\Provvigione;
use BackedEnum;  // Add this import at the top
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use UnitEnum;

class ProvvigioneResource extends Resource
{
    use HasPlanAccess;

    protected static ?string $model = Provvigione::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Provvigioni';

    protected static ?string $modelLabel = 'Provvigioni';

    protected static ?string $pluralModelLabel = 'Provvigioni';

    // protected static UnitEnum|string|null $navigationGroup = 'Pratiche';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'descrizione';

    public static function form(Schema $schema): Schema
    {
        return ProvvigioneForm::configure($schema);
    }

    /**
     * Il nome cliente vive sulla pratica collegata (Provvigione::pratica), non su un attributo
     * diretto: getAttribute() del $recordTitleAttribute di Filament non risolve le relazioni con
     * la dot-notation, quindi va risolto esplicitamente qui.
     */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record) {
            return static::getModelLabel();
        }

        $cliente = trim(($record->pratica?->cognome_cliente ?? '').' '.($record->pratica?->nome_cliente ?? ''));

        return $cliente !== '' ? $cliente : ($record->descrizione ?? static::getModelLabel());
    }

    public static function table(Table $table): Table
    {
        return ProvvigionesTable::configure($table);
    }

    /**
     * Formato dei badge «ultimo aggiornamento» nel menu, es. «Ott. 27 13:30».
     */
    public static function formatBadgeDate(Carbon $data): string
    {
        $data = $data->copy()->locale('it');

        return ucfirst(rtrim($data->translatedFormat('M'), '.')).'. '.$data->format('d H:i');
    }

    /**
     * Data e ora dell'ultima importazione.
     */
    public static function ultimoAggiornamento(): ?string
    {
        $ultimo = Provvigione::max('upload_at');

        return $ultimo ? static::formatBadgeDate(Carbon::parse($ultimo)) : null;
    }

    public static function getNavigationBadge(): ?string
    {
        return static::ultimoAggiornamento();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Ultimo aggiornamento';
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'gray';
    }

    public static function getNavigationItems(): array
    {
        return [
            ...parent::getNavigationItems(),
            NavigationItem::make('Provvigioni Attive')
                ->icon('heroicon-o-check-circle')
                ->url(static::getUrl('attive'))
                ->sort(2),
        ];
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProvvigiones::route('/'),
            'attive' => ListProvvigioniAttive::route('/attive'),
            'view' => ViewProvvigione::route('/{record}'),
            'edit' => EditProvvigione::route('/{record}/edit'),
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
