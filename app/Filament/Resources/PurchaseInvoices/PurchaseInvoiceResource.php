<?php

namespace App\Filament\Resources\PurchaseInvoices;

use App\Filament\Resources\Provvigiones\ProvvigioneResource;
use App\Filament\Resources\PurchaseInvoices\Pages\CreatePurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\Pages\EditPurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\Pages\ListPurchaseInvoices;
use App\Filament\Resources\PurchaseInvoices\RelationManagers\ProformasAfterRegistrationRelationManager;
use App\Filament\Resources\PurchaseInvoices\Schemas\PurchaseInvoiceForm;
use App\Filament\Resources\PurchaseInvoices\Tables\PurchaseInvoicesTable;
use App\Filament\Traits\HasPlanAccess;
use App\Models\PurchaseInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use UnitEnum;

class PurchaseInvoiceResource extends Resource
{
    use HasPlanAccess;

    protected static ?string $model = PurchaseInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMinus;

    protected static ?string $navigationLabel = 'Fatture passive';

    protected static ?string $modelLabel = 'Fattura passiva';

    protected static ?string $pluralModelLabel = 'Fatture passive';

    protected static UnitEnum|string|null $navigationGroup = 'Contabilita';

    protected static ?int $navigationSort = 4;

    //    protected static ?int $navigationSort = 1;

    /**
     * Data dell'ultimo caricamento (max created_at) delle fatture di acquisto.
     */
    private static function lastLoadedAt(): ?Carbon
    {
        $createdAt = PurchaseInvoice::query()->toBase()->max('created_at');

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
        return PurchaseInvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseInvoicesTable::configure($table);
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
            'index' => ListPurchaseInvoices::route('/'),
            'create' => CreatePurchaseInvoice::route('/create'),
            'edit' => EditPurchaseInvoice::route('/{record}/edit'),
        ];
    }
}
