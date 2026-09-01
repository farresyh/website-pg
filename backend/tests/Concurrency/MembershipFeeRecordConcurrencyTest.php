<?php

namespace Tests\Concurrency;

use App\Models\AdminUser;
use App\Models\LedgerEntry;
use App\Models\MembershipFeeRecord;
use App\Models\MembershipPlan;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-027 continued addendum decision 15 / Phase 6.5 (Q11). Proves the
 * fee-records unique index actually serializes two concurrent records
 * carrying the SAME idempotency key — the real "double-submit" scenario
 * the manual Record Payment action could hit — so exactly one fee (and
 * one ledger entry) is booked, not two.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class MembershipFeeRecordConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_two_concurrent_records_with_the_same_idempotency_key_book_exactly_one_fee(): void
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        $reseller = $this->primaryReseller();

        $resultFileA = tempnam(sys_get_temp_dir(), 'membership_fee_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'membership_fee_test_');

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
            PHP_BINARY, 'artisan', 'app:membership-test-record-fee',
            (string) $reseller->id, 'race-fee@example.com', (string) $plan->id, (string) $plan->fee_sen, (string) $admin->id, 'race-key', $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command($resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command($resultFileB));

        $processA->wait();
        $processB->wait();

        $resultA = file_get_contents($resultFileA);
        $resultB = file_get_contents($resultFileB);

        unlink($resultFileA);
        unlink($resultFileB);

        $this->assertSame($resultA, $resultB, 'Both processes must resolve to the same membership id.');

        $this->assertSame(
            1,
            MembershipFeeRecord::query()->where('idempotency_key', 'race-key')->count(),
            'Exactly one fee record must exist despite the race.',
        );

        $this->assertSame(
            1,
            LedgerEntry::query()->where('type', 'membership_fee')->count(),
            'Exactly one membership_fee ledger entry must exist despite the race.',
        );
    }
}
