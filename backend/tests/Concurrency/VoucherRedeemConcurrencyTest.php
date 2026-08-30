<?php

namespace Tests\Concurrency;

use App\Models\Order;
use App\Models\Voucher;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-024. Proves the voucher row lock (VCH-5, unchanged by ADR-024)
 * actually serializes two DIFFERENT orders redeeming the same voucher
 * at once — the real "same customer, two browser tabs" scenario the
 * founder raised, not just that the arithmetic is correct in
 * isolation. voucher_redemptions.order_id's own unique index (ADR-024
 * decision #2) is a second, independent guarantee against the same
 * order double-redeeming, covered separately by VoucherServiceTest's
 * idempotency case on the fast sqlite suite.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class VoucherRedeemConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_redemptions_from_two_orders_succeeds_when_combined_amount_exceeds_remaining(): void
    {
        $voucher = Voucher::query()->create([
            'code' => 'KRS-RACE-1',
            'customer_email' => 'race@example.com',
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'concurrency test',
        ]);

        $orderA = $this->order('KRS-RACE-ORDER-A');
        $orderB = $this->order('KRS-RACE-ORDER-B');

        $resultFileA = tempnam(sys_get_temp_dir(), 'voucher_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'voucher_test_');

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
            PHP_BINARY, 'artisan', 'app:voucher-test-redeem',
            (string) $voucher->id, (string) $orderId, '700', 'race@example.com', $resultFile,
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
        $failures = array_filter($outcomes, fn ($r) => str_starts_with($r, 'failed:'));

        $this->assertCount(1, $successes, 'Expected exactly one success, got: ' . json_encode($outcomes));
        $this->assertCount(1, $failures, 'Expected exactly one failure, got: ' . json_encode($outcomes));

        $this->assertSame(300, $voucher->fresh()->remaining);
    }

    private function order(string $orderNumber): Order
    {
        return Order::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'order_number' => $orderNumber,
            'customer_email' => 'race@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 0,
            'final_amount' => 1000,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ]);
    }
}
