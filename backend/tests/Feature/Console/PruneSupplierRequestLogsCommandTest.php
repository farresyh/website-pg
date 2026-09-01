<?php

namespace Tests\Feature\Console;

use App\Models\SupplierRequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-051 decision 7 — two independent retention windows on the same
 * table: validatePlayer rows follow the shorter PII-retention window
 * (matching player_validations/ADR-021), every other call_type follows
 * the longer default.
 */
class PruneSupplierRequestLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function log(array $overrides = []): SupplierRequestLog
    {
        return SupplierRequestLog::query()->create(array_merge([
            'call_type' => 'checkBalance',
            'method' => 'POST',
            'url' => 'https://api.gamevion.com/api/check-balance',
            'status_code' => 200,
            'outcome' => 'success',
        ], $overrides));
    }

    public function test_deletes_a_default_row_older_than_its_window(): void
    {
        config(['services.supplier_request_log.retention_days' => 30]);
        $stale = $this->log()->forceFill(['created_at' => now()->subDays(31)]);
        $stale->save();

        $this->artisan('app:prune-supplier-request-logs')->assertExitCode(0);

        $this->assertSame(0, SupplierRequestLog::query()->count());
    }

    public function test_keeps_a_default_row_within_its_window(): void
    {
        config(['services.supplier_request_log.retention_days' => 30]);
        $fresh = $this->log()->forceFill(['created_at' => now()->subDays(10)]);
        $fresh->save();

        $this->artisan('app:prune-supplier-request-logs')->assertExitCode(0);

        $this->assertSame(1, SupplierRequestLog::query()->count());
    }

    public function test_prunes_validate_player_rows_on_the_shorter_window_even_when_still_within_the_default(): void
    {
        config([
            'services.supplier_request_log.retention_days' => 30,
            'services.supplier_request_log.validate_player_retention_days' => 7,
        ]);
        $stale = $this->log(['call_type' => 'validatePlayer'])->forceFill(['created_at' => now()->subDays(8)]);
        $stale->save();

        $this->artisan('app:prune-supplier-request-logs')->assertExitCode(0);

        $this->assertSame(0, SupplierRequestLog::query()->count());
    }

    public function test_keeps_a_validate_player_row_within_its_own_shorter_window(): void
    {
        config([
            'services.supplier_request_log.retention_days' => 30,
            'services.supplier_request_log.validate_player_retention_days' => 7,
        ]);
        $fresh = $this->log(['call_type' => 'validatePlayer'])->forceFill(['created_at' => now()->subDays(3)]);
        $fresh->save();

        $this->artisan('app:prune-supplier-request-logs')->assertExitCode(0);

        $this->assertSame(1, SupplierRequestLog::query()->count());
    }
}
