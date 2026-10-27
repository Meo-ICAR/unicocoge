<?php

namespace App\Filament\Resources\SalesInvoices;

use App\Filament\Resources\Provvigiones\ProvvigioneResource;
use App\Filament\Resources\SalesInvoices\Pages\CreateSalesInvoice;
use App\Filament\Resources\SalesInvoices\Pages\EditSalesInvoice;
use App\Filament\Resources\SalesInvoices\Pages\ListSalesInvoices;
use App\Filament\Resources\SalesInvoices\RelationManagers\ProformasAfterRegistrationRelationManager;
use App\Filament\Resources\SalesInvoices\Schemas\SalesInvoiceForm;
use App\Filament\Resources\SalesInvoices\Tables\SalesInvoicesTable;
use App\Filament\Traits\HasPlanAccess;
use App\Models\SalesInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use UnitEnum;

class SalesInvoiceResource extends Resource
{
    use HasPlanAccess;

    protected static ?string $model = SalesInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyEuro;

    protected static ?string $navigationLabel = 'Fatture attive';

    protected static ?string $modelLabel = 'Fattura attiva';

    protected static ?string $pluralModelLabel = 'Fatture attive';

    protected static UnitEnum|string|null $navigationGroup = 'Contabilita';

    protected static ?int $navigationSort = 5;

    /**
     * Data dell'ultimo caricamento (max created_at) delle fatture di vendita.
     */
    private static function lastLoadedAt(): ?Carbon
    {
        $createdAt = SalesInvoice::query()->toBase()->max('created_at');

        return $createdAt ? Carbon::parse($createdAt) : null;
    }

    public static function getNavigationBadge(): ?string
    {
        $lastLoadedAt = static::lastLoadedAt();

        return $lastLoadedAt ? ProvvigioneResource::formatBadgeDate($lastLoadedAt) : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Data ultimo caricamento';
    }

    /**
     * Verde fino a 30 giorni dall'ultimo caricamento, blu oltre 30,
     * giallo oltre 40, rosso oltre 45.
     */
    public static function getNavigationBadgeColor(): string|array|null
    {
        $daysSinceLastLoad = static::lastLoadedAt()?->diffInDays(now());

        return match (true) {
            $daysSinceLastLoad === null => 'gray',
            $daysSinceLastLoad > 45 => 'danger',
            $daysSinceLastLoad > 40 => 'warning',
            $daysSinceLastLoad > 30 => 'info',
            default => 'success',
        };
    }

    public static function form(Schema $schema): Schema
    {
        return SalesInvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesInvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProformasAfterRegistrationRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesInvoices::route('/'),
            'create' => CreateSalesInvoice::route('/create'),
            'edit' => EditSalesInvoice::route('/{record}/edit'),
        ];
    }
}
