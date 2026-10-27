<?php

namespace App\Filament\Resources\Provvigiones\Pages;

use App\Filament\Resources\Provvigiones\ProvvigioneResource;
use App\Jobs\ImportaDaMediafacile;
use App\Models\Provvigione;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;  // CORRETTO
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class ListProvvigiones extends ListRecords
{
    protected static string $resource = ProvvigioneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importaDaMediafacile')
                ->label('Importa da Mediafacile')
                ->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()
                ->modalDescription('Importa pratiche e provvigioni degli ultimi '.ImportaDaMediafacile::GIORNI.' giorni. Al termine arriva la notifica con l\'esito.')
                ->visible(fn (): bool => static::puoImportare(auth()->user()))
                ->action(function (): void {
                    ImportaDaMediafacile::dispatch();

                    Notification::make()->title('Importazione avviata')->success()->send();
                }),
        ];
    }

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

        $ultimo = Provvigione::max('upload_at');
        $data = $ultimo ? Carbon::parse($ultimo)->locale('it') : null;
        $badge = $ultimo
            ? '<span class="fi-badge fi-color fi-color-gray" style="display:inline-block;padding:2px 10px;border-radius:9999px;background:#e5e7eb;color:#374151;font-size:.75rem">Ultimo aggiornamento: '
                .e(ucfirst(rtrim($data->translatedFormat('M'), '.')).'. '.$data->format('j H:i')).'</span><br>'
            : '';

        return new HtmlString($badge."Selezionando le provvigioni di cui si vuole emettere proforma comparira il tasto EMETTI PROFORMA.<br> Premendo il tasto verranno creati i  proforma relativi alle provvigioni selezionate . Per sospendere al mese prossimo una provvigione cliccare sul simbolo prima di 'Inserito'");
    }
}
