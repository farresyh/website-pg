<?php

namespace Tests\Concurrency;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-105 2026-10-06 decision 14: two admins (or two tabs) resend one
 * order to two different packages at once. The locked write + fulfill()'s
 * own lock give exactly one delivery, and the order row, its attempt row
 * and the ledger all name the same package and profit. The exact narrow
 * interleaving is pinned deterministically in OrderResendServiceTest.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class OrderResendConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_two_simultaneous_resends_deliver_once_and_agree_on_what_was_sent(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Race Test Supplier Resend', 'slug' => 'race-test-supplier-resend', 'api_config' => [], 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Race Test Game Resend', 'slug' => 'race-test-game-resend']);
        $package = fn (string $ref, int $cost) => Package::query()->create([
            'game_id' => $game->id, 'name' => $ref, 'cost_price' => $cost, 'standard_selling_price' => 990,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => $ref, 'is_active' => true,
        ]);
        $original = $package('ORIG', 900);
        $packageA = $package('PA', 920);
        $packageB = $package('PB', 950);

        $order = Order::query()->create(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-RACE-RESEND-1',
            'customer_email' => 'race@example.com',
            'player_id' => '123456',
            'game_id' => $game->id,
            'package_id' => $original->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'ORIG',
            'cost_price' => 900,
            'standard_selling_price' => 990,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
        ];

        $runs = collect([$packageA, $packageB])->map(function (Package $target) use ($order, $env) {
            $resultFile = tempnam(sys_get_temp_dir(), 'resend_race_test_');

            return [$resultFile, Process::path(base_path())->env($env)->start([
                PHP_BINARY, 'artisan', 'app:order-resend-test-resend', (string) $order->id, (string) $target->id, $resultFile,
            ])];
        });

        $outcomes = $runs->map(function (array $run) {
            [$resultFile, $process] = $run;
            $process->wait();
            $result = file_get_contents($resultFile);
            unlink($resultFile);

            return $result;
        })->all();

        $this->assertCount(1, array_filter($outcomes, fn ($r) => $r === 'success'), 'Expected exactly one success, got: '.json_encode($outcomes));
        $this->assertCount(1, array_filter($outcomes, fn ($r) => str_starts_with($r, 'failed:')), 'Expected exactly one refusal, got: '.json_encode($outcomes));

        $fresh = $order->fresh();
        $this->assertSame(DeliveryStatus::Delivered, $fresh->delivery_status);
        $sent = OrderResendAttempt::query()->where('order_id', $order->id)->where('outcome', 'success')->sole();
        $this->assertSame($fresh->package_id, $sent->package_id);
        $this->assertSame($fresh->cost_price, $sent->cost_price_sen);
        $this->assertSame($fresh->selling_price, $fresh->cost_price + $fresh->affiliate_profit + $fresh->platform_profit);
        $this->assertSame($fresh->platform_profit, (int) LedgerEntry::query()->where('owner_type', 'platform')->where('reference_id', $order->id)->sum('amount'));
    }
}
