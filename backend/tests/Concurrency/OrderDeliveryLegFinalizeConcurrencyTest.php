<?php

namespace Tests\Concurrency;

use App\Models\Game;
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
 * ADR-103 decision 2 / consequence-to-track: a duplicate webhook
 * delivery for the same combo LEG must never write
 * `resend_unsafe_with_same_reference`/`status` twice — proves the leg's
 * own `lockForUpdate()` (already proven for `status`/`supplier_reference`
 * by ADR-094) also serializes this ADR's new column write, exactly the
 * same way FinalizePendingDeliveryConcurrencyTest proves it at the
 * plain-order level.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class OrderDeliveryLegFinalizeConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_leg_finalize_attempts_succeeds(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Race Test Supplier Leg', 'slug' => 'race-test-supplier-leg', 'api_config' => [], 'currency' => 'IDR',
        ]);
        $game = Game::query()->create(['name' => 'Race Test Game Leg', 'slug' => 'race-test-game-leg']);
        $component = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Component', 'denomination' => 100,
            'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'RACE-LEG-REF',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 100, 'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => 0]);

        $order = Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-RACE-COMBO-LEG-1',
            'reference_number' => 'REF-RACE-COMBO-LEG-1',
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
            'delivery_status' => DeliveryStatus::Pending->value,
        ]);

        $leg = OrderDeliveryLeg::query()->create([
            'order_id' => $order->id,
            'component_package_id' => $component->id,
            'supplier_id' => $supplier->id,
            'leg_number' => 1,
            'reference_number' => 'REF-RACE-COMBO-LEG-1-L1',
            'status' => DeliveryStatus::Pending->value,
        ]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'finalize_leg_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'finalize_leg_test_');

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
            PHP_BINARY, 'artisan', 'app:order-delivery-leg-finalize-test-finalize', (string) $leg->id, $resultFile,
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

        $fresh = $leg->fresh();
        $this->assertSame(DeliveryStatus::NeedsReview, $fresh->status);
        $this->assertTrue($fresh->resend_unsafe_with_same_reference);
        // ADR-103 decision 5 — finalize never mints a new reference,
        // even under a race; the one attemptLeg() (or, here, the test
        // fixture) originally stored stays exactly as-is.
        $this->assertSame('REF-RACE-COMBO-LEG-1-L1', $fresh->reference_number);
    }
}
