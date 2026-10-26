<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientConsultantToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_consultant_flag_is_toggled_and_filtered_from_the_clients_list(): void
    {
        config(['app.env' => 'local']);
        $this->seed();
        Filament::setCurrentPanel(Filament::getDefaultPanel());
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $fromInvoice = Client::query()->create(['name' => 'Cliente da fattura', 'is_person' => true]);
        $consultant = Client::query()->create(['name' => 'Studio Alfa', 'is_person' => false, 'is_company' => true]);

        Livewire::test(ListClients::class)
            ->filterTable('is_company', true)
            ->assertCanSeeTableRecords([$consultant])
            ->assertCanNotSeeTableRecords([$fromInvoice])
            ->removeTableFilters()
            ->call('updateTableColumnState', 'is_company', (string) $fromInvoice->getKey(), true);

        $this->assertTrue($fromInvoice->fresh()->is_company);
    }
}
