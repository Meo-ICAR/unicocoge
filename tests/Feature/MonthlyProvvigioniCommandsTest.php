<?php

namespace Tests\Feature;

use App\Console\Commands\CheckProvvigioniMaturate;
use App\Console\Commands\StatusCheckCommand;
use App\Enums\Severity;
use App\Services\CheckStatus;
use Tests\TestCase;

class MonthlyProvvigioniCommandsTest extends TestCase
{
    public function test_the_attachment_travels_with_the_check_only_when_present(): void
    {
        $withFile = (new CheckStatus(2, Severity::Regular, 'x', ['filename' => 'a.csv', 'content' => 'a;b']))->toArray();

        $this->assertSame(['filename' => 'a.csv', 'content' => 'a;b'], $withFile['attachment']);
        $this->assertArrayNotHasKey('attachment', (new CheckStatus(0, Severity::Ok))->toArray());
    }

    public function test_maturate_check_is_a_status_check_and_proformas_send_is_whitelisted_with_cc(): void
    {
        $this->assertInstanceOf(StatusCheckCommand::class, $this->app->make(CheckProvvigioniMaturate::class));
        $this->assertSame(['cc', 'dry-run', 'genera'], config('schedulable_commands')['proformas:send-pending']['options']);
        $this->assertSame(['dry-run', 'solo-attivi'], config('schedulable_commands')['proformas:generate-pending']['options']);
    }
}
