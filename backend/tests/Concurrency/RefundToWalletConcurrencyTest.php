<?php

namespace Tests\Concurrency;

use App\Models\AdminUser;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-102 decision 1/2 — found mid-build, not in the original grill:
 * `OrderController::refundToWallet()`'s "did this already happen"
 * check had no real structural backstop (unlike Voucher's genuine
 * `vouchers.order_id` unique index) — `ledger_entries` carries no
 * unique index on the (type, reference_type, reference_id) tuple, so
 * two concurrent requests for the same order could both pass the
 * pre-check and both credit, a genuine double-refund. Fixed with the
 * same `Order::lockForUpdate()` every other order-mutating action
 * already acquires. Proves the lock actually serializes concurrent
 * attempts, same rationale as WithdrawalApproveConcurrencyTest/
 * LedgerWithdrawConcurrencyTest.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class RefundToWalletConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_refund_requests_succeeds(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        $affiliate = $this->primaryAffiliate();
        $reseller = Reseller::query()->create(['business_name' => 'Acme Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        $order = Order::query()->create([
            'affiliate_id' => $affiliate->id,
            'order_number' => 'KRS-REFUND-RACE-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'wallet_reseller_id' => $reseller->id,
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

        $resultFileA = tempnam(sys_get_temp_dir(), 'refund_to_wallet_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'refund_to_wallet_test_');

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
            PHP_BINARY, 'artisan', 'app:order-test-refund-to-wallet', (string) $order->id, (string) $admin->id, $resultFile,
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

        $this->assertSame(1100, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
        $this->assertSame(
            1,
            LedgerEntry::query()
                ->where('type', 'wallet_refund')
                ->where('reference_type', 'order')
                ->where('reference_id', $order->id)
                ->count(),
        );
    }
}
