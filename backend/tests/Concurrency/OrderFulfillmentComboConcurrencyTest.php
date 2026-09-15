<?php

namespace Tests\Concurrency;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-094 decision 7: fulfillCombo() reuses OrderFulfillmentService::
 * fulfill()'s exact lockForUpdate()+startDelivery() guard on the Order
 * row before touching any leg — proves that guard serializes two
 * near-simultaneous combo fulfillment attempts exactly like the plain
 * single-supplier path already does (OrderFulfillmentConcurrencyTest),
 * not just that the leg-loop logic is correct in isolation.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class OrderFulfillmentComboConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_combo_fulfillment_attempts_succeeds(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Race Test Supplier', 'slug' => 'race-test-supplier', 'api_config' => [], 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Race Test Game', 'slug' => 'race-test-game']);
        $component = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Component', 'denomination' => 100,
            'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'RACE-REF',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 100, 'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => 0]);

        $order = Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-RACE-COMBO-1',
            'customer_email' => 'race@example.com',
            'game_id' => $game->id,
            'package_id' => $combo->id,
            'player_id' => '123456',
            'supplier_id' => null,
            'supplier_product_ref' => null,
            'cost_price' => 500,
            'standard_selling_price' => 600,
            'selling_price' => 700,
            'transaction_fee' => 100,
            'final_amount' => 800,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'fulfill_combo_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'fulfill_combo_test_');

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

        // Exactly one leg row, delivered once — a lost race that slipped
        // past the lock would have produced two (or a duplicate-reference
        // supplier rejection on the second attempt's leg).
        $this->assertSame(1, OrderDeliveryLeg::query()->where('order_id', $order->id)->count());

        // Exactly two order_profit entries (platform + affiliate) — not
        // four, which is what a lost race would have produced.
        $this->assertSame(2, LedgerEntry::query()->where('reference_id', $order->id)->count());
    }
}
