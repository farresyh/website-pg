<?php

namespace Tests\Concurrency;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherCashRefund;
use App\Models\VoucherRedemption;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-004 2026-11-12 addendum: the voucher row lock in
 * VoucherService::refundInCash() serializes a cash refund against a second
 * refund and against a checkout redeeming the same voucher. Never two
 * payouts, never a payout while an order holds part of the balance.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class VoucherCashRefundConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    private const ENV = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
        'DB_DATABASE' => 'kerox', 'DB_USERNAME' => 'kerox', 'DB_PASSWORD' => 'kerox',
    ];

    public function test_two_refunds_of_one_voucher_pay_out_once(): void
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        $voucher = $this->customerCashVoucher('KRS-REFUND-RACE', 1000);
        [$file1, $file2] = [tempnam(sys_get_temp_dir(), 'refund_race_'), tempnam(sys_get_temp_dir(), 'refund_race_')];

        $first = Process::path(base_path())->env(self::ENV)->start([PHP_BINARY, 'artisan', 'app:voucher-test-refund-in-cash', (string) $voucher->id, (string) $admin->id, $file1]);
        $second = Process::path(base_path())->env(self::ENV)->start([PHP_BINARY, 'artisan', 'app:voucher-test-refund-in-cash', (string) $voucher->id, (string) $admin->id, $file2]);
        $first->wait();
        $second->wait();

        $outcomes = [file_get_contents($file1), file_get_contents($file2)];
        unlink($file1);
        unlink($file2);

        $this->assertCount(1, array_filter($outcomes, fn ($r) => $r === 'success:1000'), json_encode($outcomes));
        $this->assertCount(1, array_filter($outcomes, fn ($r) => str_starts_with($r, 'failed:')), json_encode($outcomes));
        $this->assertSame(1, VoucherCashRefund::query()->count());
        $this->assertSame('refunded', $voucher->fresh()->status);
        $this->assertSame(0, $voucher->fresh()->remaining);
    }

    public function test_a_refund_racing_a_redeem_never_pays_out_a_balance_an_order_holds(): void
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        $voucher = $this->customerCashVoucher('KRS-REFUND-REDEEM', 1000);
        $order = Order::query()->create([
            'placed_via' => 'storefront', 'affiliate_id' => $this->primaryAffiliate()->id, 'order_number' => 'KRS-REFUND-REDEEM-ORDER',
            'customer_email' => 'refund-race@example.com', 'player_id' => '123456', 'cost_price' => 900,
            'standard_selling_price' => 900, 'selling_price' => 1000, 'transaction_fee' => 0, 'final_amount' => 600,
            'platform_profit' => 100, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value, 'delivery_status' => DeliveryStatus::NotStarted->value,
        ]);
        [$refundFile, $redeemFile] = [tempnam(sys_get_temp_dir(), 'refund_race_'), tempnam(sys_get_temp_dir(), 'redeem_race_')];

        $refund = Process::path(base_path())->env(self::ENV)->start([PHP_BINARY, 'artisan', 'app:voucher-test-refund-in-cash', (string) $voucher->id, (string) $admin->id, $refundFile]);
        $redeem = Process::path(base_path())->env(self::ENV)->start([PHP_BINARY, 'artisan', 'app:voucher-test-redeem', (string) $voucher->id, (string) $order->id, '400', 'refund-race@example.com', $redeemFile]);
        $refund->wait();
        $redeem->wait();

        $refunded = file_get_contents($refundFile);
        $redeemed = file_get_contents($redeemFile);
        unlink($refundFile);
        unlink($redeemFile);

        // Exactly one wins: refund first means the redeem finds a refunded voucher; redeem first means the refund sees the open reservation.
        $this->assertNotSame(str_starts_with($refunded, 'success:'), $redeemed === 'success', "refund: {$refunded}, redeem: {$redeemed}");
        $this->assertSame(0, VoucherRedemption::query()->where('status', 'reserved')->whereHas('voucher', fn ($q) => $q->where('status', 'refunded'))->count());
        $fresh = $voucher->fresh();
        $this->assertSame(1000, $fresh->remaining + (int) VoucherCashRefund::query()->sum('amount_sen') + (int) VoucherRedemption::query()->sum('amount'), 'no sen is created or lost');
    }

    private function customerCashVoucher(string $code, int $amount): Voucher
    {
        $order = Order::query()->create([
            'placed_via' => 'storefront', 'affiliate_id' => $this->primaryAffiliate()->id, 'order_number' => $code.'-SRC',
            'customer_email' => 'refund-race@example.com', 'player_id' => '123456', 'cost_price' => 900,
            'standard_selling_price' => 900, 'selling_price' => 1000, 'transaction_fee' => 100, 'final_amount' => 1100,
            'platform_profit' => 100, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        return Voucher::query()->create([
            'order_id' => $order->id, 'source' => Voucher::SOURCE_CUSTOMER_CASH, 'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => $code, 'customer_email' => 'refund-race@example.com', 'amount' => $amount, 'remaining' => $amount,
            'status' => 'active', 'reason' => 'concurrency test',
        ]);
    }
}
