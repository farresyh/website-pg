<?php

namespace Tests\Concurrency;

use App\Models\AdminUser;
use App\Models\LedgerEntry;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\Support\PartialComboOrders;
use Tests\TestCase;

/**
 * ADR-094 decision 32 — two admins pressing Issue Voucher on the same
 * partially delivered retail order at once: exactly one compensates.
 * The voucher instrument's counterpart to RefundToWalletConcurrencyTest,
 * and it also covers the parts only this path has: the paid-with
 * voucher's partial restore and the delivered legs' profit credit.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class OrderSettlementConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    use PartialComboOrders;

    public function test_only_one_of_two_simultaneous_voucher_settlements_succeeds(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        // S = 5000: 1000 paid with voucher X, 4000 cash (+100 fee); u = 0.4.
        $order = $this->partialComboOrder(['voucher_discount' => 1000, 'final_amount' => 4100]);
        $x = Voucher::query()->create([
            'affiliate_id' => $order->affiliate_id, 'code' => 'VC-RACE-X', 'customer_email' => $order->customer_email,
            'amount' => 1000, 'remaining' => 1000, 'status' => 'active', 'reason' => 'test', 'created_by' => $admin->id,
        ]);
        app(VoucherService::class)->redeem($x->id, $order->id, 1000, $order->customer_email, null, $order->affiliate_id);

        $resultFileA = tempnam(sys_get_temp_dir(), 'settle_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'settle_test_');

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
            PHP_BINARY, 'artisan', 'app:order-test-settle', (string) $order->id, (string) $admin->id, $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command($resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command($resultFileB));

        $processA->wait();
        $processB->wait();

        $outcomes = [file_get_contents($resultFileA), file_get_contents($resultFileB)];
        unlink($resultFileA);
        unlink($resultFileB);

        $successes = array_filter($outcomes, fn ($r) => $r === 'success');
        $failures = array_filter($outcomes, fn ($r) => str_starts_with($r, 'failed:'));

        $this->assertCount(1, $successes, 'Expected exactly one success, got: '.json_encode($outcomes));
        $this->assertCount(1, $failures, 'Expected exactly one failure, got: '.json_encode($outcomes));

        // One compensation voucher of the cash share, X restored once.
        $this->assertSame([1600], Voucher::query()->where('order_id', $order->id)->pluck('amount')->all());
        $this->assertSame(400, $x->fresh()->remaining);
        $this->assertSame(400, VoucherRedemption::query()->where('order_id', $order->id)->value('restored_amount'));

        // Ledger: one voucher_issued, one order_profit pair.
        $this->assertSame(1, LedgerEntry::query()->where('type', 'voucher_issued')->count());
        $this->assertSame(2, LedgerEntry::query()->where('type', 'order_profit')->where('reference_id', $order->id)->count());
        $this->assertSame(600, (int) LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->sum('amount'));
    }
}
