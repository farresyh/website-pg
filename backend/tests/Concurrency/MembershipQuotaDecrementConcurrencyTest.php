<?php

namespace Tests\Concurrency;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-027 Phase 6. Proves the membership row lock actually serializes
 * two DIFFERENT orders decrementing the same membership's quota at
 * once — the real "two browser tabs, same member" scenario, not just
 * that the arithmetic is correct in isolation.
 * `membership_quota_debits.order_id`'s own unique index is a second,
 * independent guarantee against the same order double-decrementing,
 * covered separately by MembershipQuotaServiceTest's idempotency case
 * on the fast sqlite suite.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class MembershipQuotaDecrementConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_decrements_from_two_orders_succeeds_when_combined_amount_exceeds_remaining(): void
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'race@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 1000,
            'expires_at' => now()->addDays(20),
        ]);

        $orderA = $this->order('KRS-QUOTA-RACE-A');
        $orderB = $this->order('KRS-QUOTA-RACE-B');

        $resultFileA = tempnam(sys_get_temp_dir(), 'membership_quota_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'membership_quota_test_');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
        ];

        $command = fn (int $orderId, string $resultFile) => [
            PHP_BINARY, 'artisan', 'app:membership-test-quota-decrement',
            (string) $membership->id, (string) $orderId, '700', $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command($orderA->id, $resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command($orderB->id, $resultFileB));

        $processA->wait();
        $processB->wait();

        $resultA = file_get_contents($resultFileA);
        $resultB = file_get_contents($resultFileB);

        unlink($resultFileA);
        unlink($resultFileB);

        $outcomes = [$resultA, $resultB];
        $successes = array_filter($outcomes, fn ($r) => $r === 'success');
        $failures = array_filter($outcomes, fn ($r) => $r === 'failed');

        $this->assertCount(1, $successes, 'Expected exactly one success, got: '.json_encode($outcomes));
        $this->assertCount(1, $failures, 'Expected exactly one failure, got: '.json_encode($outcomes));

        $this->assertSame(300, $membership->fresh()->quota_remaining_sen);
    }

    private function order(string $orderNumber): Order
    {
        return Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => $orderNumber,
            'customer_email' => 'race@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1040,
            'transaction_fee' => 0,
            'final_amount' => 1040,
            'platform_profit' => 140,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ]);
    }
}
