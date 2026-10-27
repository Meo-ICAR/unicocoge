<?php

namespace Tests\Feature;

use App\Filament\Resources\Provvigiones\Pages\ListProvvigiones;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImportaMediafacileVisibilityTest extends TestCase
{
    #[DataProvider('ruoli')]
    public function test_only_admins_and_accountants_can_import(string $role, array $employeeRoles, bool $atteso): void
    {
        $user = new User(['role' => $role]);
        $user->setRelation('employee', (object) ['employee_roles' => $employeeRoles]);

        $this->assertSame($atteso, ListProvvigiones::puoImportare($user));
    }

    public static function ruoli(): array
    {
        return [
            'super admin' => ['super_admin', [], true],
            'admin' => ['admin', [], true],
            'contabile' => ['employee', ['contabile'], true],
            'contabile responsabile' => ['employee', ['contabile responsabile'], true],
            'altro dipendente' => ['employee', ['commerciale'], false],
            'utente' => ['user', [], false],
        ];
    }

    public function test_guests_cannot_import(): void
    {
        $this->assertFalse(ListProvvigiones::puoImportare(null));
    }
}
