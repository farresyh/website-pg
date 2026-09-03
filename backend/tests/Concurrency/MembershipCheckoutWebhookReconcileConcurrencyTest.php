<?php

namespace Tests\Concurrency;

use App\Models\LedgerEntry;
use App\Models\Membership;
use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipFeeRecord;
use App\Models\MembershipPlan;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-068 S14 — the webhook and the daily reconcile sweep can both try
 * to complete the same paid membership_checkout_attempts row at the
 * same time. Proves that racing `completePaidAttempt()` from two real
 * OS processes still books exactly one membership, one fee record and
 * one ledger entry, with the attempt landing on `paid`.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class MembershipCheckoutWebhookReconcileConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_processes_completing_the_same_attempt_book_exactly_one_membership(): void
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $reseller = $this->primaryReseller();

        $attempt = MembershipCheckoutAttempt::query()->create([
            'reseller_id' => $reseller->id,
            'email' => 'race-sub@example.com',
            'membership_plan_id' => $plan->id,
            'fee_sen' => $plan->fee_sen,
            'total_charged_sen' => $plan->fee_sen + 100,
            'channel_code' => 'fpx',
            'subscription_number' => 'MS-RACETEST',
            'idempotency_key' => 'idem-race-sub',
            'payment_ref' => 'chip-purchase-race',
            'status' => MembershipCheckoutAttemptStatus::Pending->value,
        ]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'membership_sub_race_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'membership_sub_race_');

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
            PHP_BINARY, 'artisan', 'app:membership-test-complete-attempt', $attempt->subscription_number, $resultFile,
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
        $this->assertStringNotContainsString('error', $resultA, "Process A errored: {$resultA}");

        $this->assertSame(1, Membership::query()->where('email', 'race-sub@example.com')->count());
        $this->assertSame(1, MembershipFeeRecord::query()->where('idempotency_key', 'MS-RACETEST')->count());
        $this->assertSame(1, LedgerEntry::query()->where('type', 'membership_fee')->count());
        $this->assertSame(MembershipCheckoutAttemptStatus::Paid, $attempt->fresh()->status);
    }
}
