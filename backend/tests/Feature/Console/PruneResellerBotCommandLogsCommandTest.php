<?php

namespace Tests\Feature\Console;

use App\Models\ResellerBotCommandLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PR-F build addendum decision 2 — 7-day retention on the failure-only command log. */
class PruneResellerBotCommandLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function log(string $createdAt): ResellerBotCommandLog
    {
        $log = ResellerBotCommandLog::query()->create([
            'reseller_id' => null,
            'whatsapp_group_id' => 'g@g.us',
            'raw_command' => '.blah',
            'failure_reason' => 'unrecognized_command',
        ]);
        $log->forceFill(['created_at' => $createdAt])->save();

        return $log;
    }

    public function test_deletes_a_row_older_than_the_retention_window(): void
    {
        config(['services.openwa.command_log_retention_days' => 7]);
        $this->log(now()->subDays(8)->toDateTimeString());

        $this->artisan('app:prune-reseller-bot-command-logs')->assertExitCode(0);

        $this->assertSame(0, ResellerBotCommandLog::query()->count());
    }

    public function test_keeps_a_row_within_the_retention_window(): void
    {
        config(['services.openwa.command_log_retention_days' => 7]);
        $this->log(now()->subDays(2)->toDateTimeString());

        $this->artisan('app:prune-reseller-bot-command-logs')->assertExitCode(0);

        $this->assertSame(1, ResellerBotCommandLog::query()->count());
    }
}
