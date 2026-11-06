<?php

namespace Tests\Concurrency;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-036. Proves the row lock in VoucherService::merge() actually
 * serializes two DIFFERENT merge attempts that share one voucher —
 * two admins concurrently selecting overlapping voucher sets, not
 * just that the arithmetic is correct in isolation. Voucher A is
 * shared between attempt 1 ([A, B]) and attempt 2 ([A, C]); whichever
 * loses the race must see A already `merged` and fail cleanly, never
 * both succeeding and consuming A twice.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class VoucherMergeConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_overlapping_merge_attempts_succeeds(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);

        $a = $this->voucher('KRS-MERGE-RACE-A', 1000);
        $b = $this->voucher('KRS-MERGE-RACE-B', 500);
        $c = $this->voucher('KRS-MERGE-RACE-C', 300);

        $resultFile1 = tempnam(sys_get_temp_dir(), 'voucher_merge_test_');
        $resultFile2 = tempnam(sys_get_temp_dir(), 'voucher_merge_test_');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
        ];

        $command = fn (array $voucherIds, string $resultFile) => [
            PHP_BINARY, 'artisan', 'app:voucher-test-merge',
            implode(',', $voucherIds), (string) $admin->id, $resultFile,
        ];

        $process1 = Process::path(base_path())->env($env)->start($command([$a->id, $b->id], $resultFile1));
        $process2 = Process::path(base_path())->env($env)->start($command([$a->id, $c->id], $resultFile2));

        $process1->wait();
        $process2->wait();

        $result1 = file_get_contents($resultFile1);
        $result2 = file_get_contents($resultFile2);

        unlink($resultFile1);
        unlink($resultFile2);

        $outcomes = [$result1, $result2];
        $successes = array_filter($outcomes, fn ($r) => str_starts_with($r, 'success:'));
        $failures = array_filter($outcomes, fn ($r) => str_starts_with($r, 'failed:'));

        $this->assertCount(1, $successes, 'Expected exactly one success, got: '.json_encode($outcomes));
        $this->assertCount(1, $failures, 'Expected exactly one failure, got: '.json_encode($outcomes));

        $a->refresh();
        $this->assertSame('merged', $a->status);
        $this->assertSame(0, $a->remaining);

        // Exactly one of B/C was consumed by the winning merge — the
        // other was never touched by the losing attempt (it failed
        // before creating anything, per merge()'s all-or-nothing
        // transaction).
        $bMerged = $b->fresh()->status === 'merged';
        $cMerged = $c->fresh()->status === 'merged';
        $this->assertNotSame($bMerged, $cMerged, 'Expected exactly one of B/C to be merged, not both or neither.');

        $this->assertDatabaseCount('voucher_merges', 2);
    }

    /**
     * ADR-036 2026-11-07 addendum: a redeem racing a merge of the same
     * voucher. Whichever takes the voucher lock first wins, and the outcome
     * is never a reservation on a merged voucher, the state that strands
     * value when the order later gives it back.
     */
    public function test_a_redeem_racing_a_merge_never_leaves_a_reservation_on_a_merged_voucher(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        $a = $this->voucher('KRS-RACE-REDEEM-A', 1000);
        $b = $this->voucher('KRS-RACE-REDEEM-B', 500);
        $order = Order::query()->create([
            'placed_via' => 'storefront', 'affiliate_id' => $this->primaryAffiliate()->id, 'order_number' => 'KRS-RACE-REDEEM',
            'customer_email' => 'merge-race@example.com', 'player_id' => '123456', 'cost_price' => 900,
            'standard_selling_price' => 900, 'selling_price' => 1000, 'transaction_fee' => 0, 'final_amount' => 1000,
            'platform_profit' => 100, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value, 'delivery_status' => DeliveryStatus::NotStarted->value,
        ]);

        $mergeFile = tempnam(sys_get_temp_dir(), 'voucher_merge_test_');
        $redeemFile = tempnam(sys_get_temp_dir(), 'voucher_redeem_test_');
        $env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox', 'DB_USERNAME' => 'kerox', 'DB_PASSWORD' => 'kerox',
        ];

        $merge = Process::path(base_path())->env($env)->start([
            PHP_BINARY, 'artisan', 'app:voucher-test-merge', "{$a->id},{$b->id}", (string) $admin->id, $mergeFile,
        ]);
        $redeem = Process::path(base_path())->env($env)->start([
            PHP_BINARY, 'artisan', 'app:voucher-test-redeem', (string) $a->id, (string) $order->id, '400', 'merge-race@example.com', $redeemFile,
        ]);

        $merge->wait();
        $redeem->wait();

        $merged = file_get_contents($mergeFile);
        $redeemed = file_get_contents($redeemFile);
        unlink($mergeFile);
        unlink($redeemFile);

        $this->assertNotSame(str_starts_with($merged, 'success:'), $redeemed === 'success', "merge: {$merged}, redeem: {$redeemed}");
        $this->assertSame(0, VoucherRedemption::query()->where('status', 'reserved')
            ->whereHas('voucher', fn ($q) => $q->where('status', 'merged'))->count());
    }

    private function voucher(string $code, int $remaining): Voucher
    {
        return Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => $code,
            'customer_email' => 'merge-race@example.com',
            'amount' => $remaining,
            'remaining' => $remaining,
            'status' => 'active',
            'reason' => 'concurrency test',
        ]);
    }
}
