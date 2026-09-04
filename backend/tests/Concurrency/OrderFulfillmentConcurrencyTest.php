<?php

namespace Tests\Concurrency;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Proves the fix for the concurrency finding from the 2026-07-24 security
 * review: OrderFulfillmentService::fulfill() previously had no row lock,
 * so two near-simultaneous calls for the same Order (e.g. a duplicate
 * webhook delivery, which Xendit's own docs call out as expected
 * behavior) could each generate a different reference_number and submit
 * two separate orders to the supplier — double-delivering game credits
 * and double-crediting the ledger for a single payment. Same rationale
 * as LedgerWithdrawConcurrencyTest/VoucherRedeemConcurrencyTest: proves
 * the lock actually serializes concurrent attempts, not just that the
 * logic is correct in isolation.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class OrderFulfillmentConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_fulfillment_attempts_succeeds(): void
    {
        // ADR-031: fulfill() now resolves its adapter by the order's
        // own supplier_id — app:order-fulfillment-test-fulfill binds
        // its fake under this real supplier's slug (see that command).
        $supplier = Supplier::query()->create([
            'name' => 'Race Test Supplier', 'slug' => 'race-test-supplier', 'api_config' => [], 'currency' => 'MYR',
        ]);

        $order = Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-RACE-1',
            'customer_email' => 'race@example.com',
            'player_id' => '123456',
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'FFP5',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'fulfill_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'fulfill_test_');

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
            PHP_BINARY, 'artisan', 'app:order-fulfillment-test-fulfill', (string) $order->id, $resultFile,
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

        $this->assertCount(1, $successes, 'Expected exactly one success, got: '.json_encode($outcomes));
        $this->assertCount(1, $failures, 'Expected exactly one failure, got: '.json_encode($outcomes));

        $fresh = Order::query()->findOrFail($order->id);
        $this->assertSame(DeliveryStatus::Delivered, $fresh->delivery_status);
        $this->assertNotNull($fresh->reference_number);

        // Exactly two order_profit entries (platform + affiliate) — not
        // four, which is what a lost race would have produced.
        $this->assertSame(2, LedgerEntry::query()->where('reference_id', $order->id)->count());
    }
}
