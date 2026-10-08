<?php

namespace Tests\Concurrency;

use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Item 63 (2026-10-04): a Paid and a Failed answer for the same order
 * race OrderPaymentOutcomeService in two real processes (the CHIP webhook
 * vs reconciliation). Either order of arrival is legitimate; what must
 * never happen is the old outcome — fulfilment dispatched for an order
 * whose voucher was already given back.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class OrderPaymentOutcomeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_racing_paid_and_failed_never_fulfil_an_order_whose_voucher_went_back(): void
    {
        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-OUTCOME-RACE',
            'customer_email' => 'buyer@example.com',
            'amount' => 300,
            'remaining' => 0,
            'status' => 'exhausted',
            'reason' => 'test',
        ]);
        $order = Order::query()->create(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-OUTCOMERACE',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 1000,
            'selling_price' => 1000,
            'voucher_id' => $voucher->id,
            'voucher_discount' => 300,
            'transaction_fee' => 100,
            'final_amount' => 800,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_ref' => 'pr-outcome-race',
            'payment_status' => 'pending',
            'delivery_status' => 'not_started',
        ]);
        VoucherRedemption::query()->create(['voucher_id' => $voucher->id, 'order_id' => $order->id, 'amount' => 300, 'status' => 'reserved']);

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
            'QUEUE_CONNECTION' => 'database',
        ];

        $files = ['paid' => tempnam(sys_get_temp_dir(), 'outcome_paid_'), 'failed' => tempnam(sys_get_temp_dir(), 'outcome_failed_')];
        $processes = [];
        foreach ($files as $status => $file) {
            $processes[] = Process::path(base_path())->env($env)->start([
                PHP_BINARY, 'artisan', 'app:payment-test-apply-outcome', (string) $order->id, $status, '800', $file,
            ]);
        }
        foreach ($processes as $process) {
            $process->wait();
        }

        $results = array_map(fn ($file) => (string) file_get_contents($file), $files);
        array_map('unlink', $files);
        foreach ($results as $result) {
            $this->assertStringNotContainsString('error:', $result, $result);
        }

        $fresh = $order->fresh();
        $redemption = VoucherRedemption::query()->where('order_id', $order->id)->firstOrFail();
        $fulfilmentQueued = DB::table('jobs')->where('payload', 'like', '%FulfillOrderJob%')->exists();

        $this->assertSame('paid', $fresh->payment_status->value, 'the payment is always recorded');

        if ($results['failed'] === 'Failed') {
            // Failed first: voucher given back, so the Paid must be flagged.
            $this->assertSame('FlaggedForReview', $results['paid']);
            $this->assertSame('restored', $redemption->status);
            $this->assertSame('needs_review', $fresh->delivery_status->value);
            $this->assertFalse($fulfilmentQueued, 'never fulfil after the voucher went back');
        } else {
            // Paid first: the Failed is a no-op and the voucher stays spent.
            $this->assertSame('Fulfilling', $results['paid']);
            $this->assertSame('AlreadyProcessed', $results['failed']);
            $this->assertSame('reserved', $redemption->status);
            $this->assertSame(0, $voucher->fresh()->remaining);
            $this->assertTrue($fulfilmentQueued);
        }
    }
}
