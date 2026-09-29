<?php

namespace Tests\Concurrency;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * 2026-09-29 audit finding M-6 (ADR-024 addendum): a full-voucher-cover
 * checkout used to stamp the Order Paid and dispatch fulfillment BEFORE
 * VoucherService::redeem() (the real, row-locked serialization point)
 * ever ran — nothing analogous to a real payment protected it, unlike
 * the partial-cover path. Two concurrent checkouts citing the same
 * exactly-covering voucher could both pass the unlocked preview() and
 * both ship real goods for free, repeatably. Proves two genuinely
 * separate processes racing `CheckoutService::initiate()` against the
 * same voucher: exactly one settles Paid, the other fails Failed, and
 * the voucher is spent exactly once.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class CheckoutSettleWithVoucherConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_simultaneous_full_cover_checkouts_settle_the_voucher_exactly_once(): void
    {
        $affiliate = $this->primaryAffiliate();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends-race', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '500 Diamonds', 'denomination' => 500,
            'cost_price' => 1, 'standard_selling_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'race-test',
        ]);
        $voucher = Voucher::query()->create([
            'affiliate_id' => $affiliate->id,
            'code' => 'KRS-FULLCOVER-RACE',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'checkout_fullcover_race_a_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'checkout_fullcover_race_b_');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
            // The winning side dispatches FulfillOrderJob — this test only
            // cares about the voucher-redemption race, not a real supplier
            // call, so queue it (never run, no worker in this test) rather
            // than let a 'sync' connection execute it inline and fail on
            // missing supplier fixture data unrelated to what's being proven.
            'QUEUE_CONNECTION' => 'database',
        ];

        $command = fn (string $idempotencyKey, string $resultFile) => [
            PHP_BINARY, 'artisan', 'app:checkout-test-initiate-full-cover',
            (string) $affiliate->id, (string) $game->id, (string) $package->id,
            $voucher->code, '500', $idempotencyKey, $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command('idem-race-a', $resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command('idem-race-b', $resultFileB));

        $processA->wait();
        $processB->wait();

        $resultA = file_get_contents($resultFileA);
        $resultB = file_get_contents($resultFileB);

        unlink($resultFileA);
        unlink($resultFileB);

        $this->assertStringNotContainsString('error:', $resultA, "Process A errored: {$resultA}");
        $this->assertStringNotContainsString('error:', $resultB, "Process B errored: {$resultB}");

        $outcomes = [$resultA, $resultB];
        sort($outcomes);
        $this->assertSame('checkout_failed', explode(':', $outcomes[0])[0], 'exactly one side must lose the race');
        $this->assertSame('paid', $outcomes[1], 'exactly one side must win and settle Paid');

        $this->assertSame(0, $voucher->fresh()->remaining, 'the voucher must be spent exactly once, not left over or double-spent');
        $this->assertSame(1, VoucherRedemption::query()->where('voucher_id', $voucher->id)->count());

        $paidOrders = Order::query()->where('payment_status', 'paid')->count();
        $failedOrders = Order::query()->where('payment_status', 'failed')->count();
        $this->assertSame(1, $paidOrders);
        $this->assertSame(1, $failedOrders, 'the losing order must be visible as Failed, not silently missing or fulfilled');
    }
}
