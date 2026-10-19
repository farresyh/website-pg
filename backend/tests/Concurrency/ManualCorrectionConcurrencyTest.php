<?php

namespace Tests\Concurrency;

use App\Models\AdminUser;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\Voucher;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-125 decision 42: a Failed → Delivered correction on a manual order
 * races Issue Voucher, or a second correction, on the order row lock.
 * Exactly one wins; the order is never both compensated and delivered,
 * and profit and the drawdown are recorded at most once.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class ManualCorrectionConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    private function failedManualOrder(): Order
    {
        $supplier = Supplier::query()->create([
            'name' => 'Roblox Race', 'slug' => 'roblox-race', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR',
        ]);

        return Order::query()->create(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-MANUAL-RACE-1',
            'customer_email' => 'race@example.com',
            'player_id' => 'race@example.com',
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'MANUAL-1',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
    }

    /** @param  list<list<string>>  $commands */
    private function race(array $commands): array
    {
        $env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox', 'DB_USERNAME' => 'kerox', 'DB_PASSWORD' => 'kerox',
        ];
        $files = array_map(fn () => tempnam(sys_get_temp_dir(), 'manual_race_'), $commands);
        $processes = array_map(
            fn (array $command, string $file) => Process::path(base_path())->env($env)->start([PHP_BINARY, 'artisan', ...$command, $file]),
            $commands,
            $files,
        );
        array_map(fn ($p) => $p->wait(), $processes);

        $outcomes = array_map(fn (string $f) => file_get_contents($f), $files);
        array_map('unlink', $files);

        return $outcomes;
    }

    private function drawdownCount(Order $order): int
    {
        return SupplierLedgerEntry::query()->where('reference_type', 'order')->where('reference_id', $order->id)->count();
    }

    public function test_a_correction_racing_issue_voucher_leaves_exactly_one_outcome(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        $order = $this->failedManualOrder();

        $outcomes = $this->race([
            ['app:order-test-manual-correct', (string) $order->id],
            ['app:order-test-settle', (string) $order->id, (string) $admin->id],
        ]);

        $this->assertCount(1, array_filter($outcomes, fn ($r) => $r === 'success'), json_encode($outcomes));

        $fresh = $order->fresh();
        $hasVoucher = Voucher::query()->where('order_id', $order->id)->exists();

        if ($fresh->delivery_status === DeliveryStatus::Delivered) {
            $this->assertFalse($hasVoucher);
            $this->assertSame(1, $this->drawdownCount($order));
            $this->assertSame(100, (int) LedgerEntry::query()->where('reference_id', $order->id)->where('owner_type', 'platform')->sum('amount'));
        } else {
            $this->assertSame(DeliveryStatus::Failed, $fresh->delivery_status);
            $this->assertTrue($hasVoucher);
            $this->assertSame(0, $this->drawdownCount($order));
        }
    }

    public function test_two_simultaneous_corrections_deliver_once(): void
    {
        $order = $this->failedManualOrder();

        $outcomes = $this->race([
            ['app:order-test-manual-correct', (string) $order->id],
            ['app:order-test-manual-correct', (string) $order->id],
        ]);

        $this->assertCount(1, array_filter($outcomes, fn ($r) => $r === 'success'), json_encode($outcomes));
        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
        $this->assertSame(1, $this->drawdownCount($order));
        $this->assertSame(100, (int) LedgerEntry::query()->where('reference_id', $order->id)->where('owner_type', 'platform')->sum('amount'));
    }
}
