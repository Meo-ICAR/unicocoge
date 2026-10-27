<?php

namespace App\Filament\Resources\Provvigiones\Pages;

use App\Filament\Resources\Provvigiones\ProvvigioneResource;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;  // CORRETTO
use Illuminate\Support\HtmlString;

class ListProvvigiones extends ListRecords
{
    protected static string $resource = ProvvigioneResource::class;

    /**
     * Admin e super admin, oppure dipendenti con ruolo «contabile» o «contabile responsabile».
     */
    public static function puoImportare(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (in_array($user->role instanceof \BackedEnum ? $user->role->value : $user->role, ['admin', 'super_admin'], true)) {
            return true;
        }

        $ruoli = (array) $user->employee?->employee_roles;

        return array_intersect(['contabile', 'contabile responsabile'], $ruoli) !== [];
    }

    // Aggiunge il sottotitolo
    public function getSubheading(): string|Htmlable|null
    {
        // $record = $this->getRecord();

        return new HtmlString("Selezionando le provvigioni di cui si vuole emettere proforma comparira il tasto EMETTI PROFORMA.<br> Premendo il tasto verranno creati i  proforma relativi alle provvigioni selezionate . Per sospendere al mese prossimo una provvigione cliccare sul simbolo prima di 'Inserito'");
    }
}
