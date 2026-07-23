<?php

namespace Tests\Concurrency;

use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Proves the ledger_accounts lock (docs/adr.md ADR-002 addendum) actually
 * prevents a double-withdrawal race — not just that the arithmetic is
 * correct in isolation. Two genuinely separate PHP processes (separate DB
 * connections, real OS-level concurrency) each attempt to withdraw more
 * than half the balance at the same time; only one may succeed.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class LedgerWithdrawConcurrencyTest extends TestCase
{
    // NOT RefreshDatabase: it wraps each test in one uncommitted transaction
    // that's rolled back at the end, so data seeded here would never be
    // visible to the separate subprocess connections spawned below.
    // DatabaseMigrations runs migrate:fresh per test with real commits.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_withdrawals_succeeds_when_combined_amount_exceeds_balance(): void
    {
        $ledger = app(LedgerService::class);
        $ownerType = 'reseller';
        $ownerId = 999;

        $ledger->openAccount($ownerType, $ownerId);
        $ledger->credit($ownerType, $ownerId, 1000, 'order_profit', 'order', 1);

        $resultFileA = tempnam(sys_get_temp_dir(), 'ledger_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'ledger_test_');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
        ];

        $command = fn (string $resultFile) => [
            PHP_BINARY, 'artisan', 'app:ledger-test-withdraw', $ownerType, (string) $ownerId, '700', $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command($resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command($resultFileB));

        $processA->wait();
        $processB->wait();

        $resultA = file_get_contents($resultFileA);
        $resultB = file_get_contents($resultFileB);

        unlink($resultFileA);
        unlink($resultFileB);

        $outcomes = [$resultA, $resultB];
        $successes = array_filter($outcomes, fn ($r) => $r === 'success');
        $failures = array_filter($outcomes, fn ($r) => str_starts_with($r, 'failed:'));

        $this->assertCount(1, $successes, "Expected exactly one success, got: " . json_encode($outcomes));
        $this->assertCount(1, $failures, "Expected exactly one failure, got: " . json_encode($outcomes));
        $this->assertSame(300, $ledger->balance($ownerType, $ownerId));
    }
}
