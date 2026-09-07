<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ADR-077 decision 3 — sweeper for the lazily-expiring `database` cache store table. */
class PruneStaleCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    private function cacheRow(string $key, int $expiration): void
    {
        DB::table('cache')->insert([
            'key' => $key,
            'value' => serialize('x'),
            'expiration' => $expiration,
        ]);
    }

    public function test_deletes_only_expired_rows(): void
    {
        $this->cacheRow('expired', now()->subMinute()->getTimestamp());
        $this->cacheRow('live', now()->addHour()->getTimestamp());
        $this->cacheRow('forever', now()->addYears(5)->getTimestamp());

        $this->artisan('app:prune-stale-cache')->assertExitCode(0);

        $this->assertSame(
            ['forever', 'live'],
            DB::table('cache')->orderBy('key')->pluck('key')->all(),
        );
    }

    public function test_is_a_no_op_when_nothing_is_stale(): void
    {
        $this->cacheRow('live', now()->addHour()->getTimestamp());

        $this->artisan('app:prune-stale-cache')
            ->expectsOutputToContain('Pruned 0 expired row(s)')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('cache')->count());
    }
}
