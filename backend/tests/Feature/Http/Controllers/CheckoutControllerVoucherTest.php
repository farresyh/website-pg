<?php

namespace Tests\Feature\Http\Controllers;

use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\CheckoutControllerFixtures;
use Tests\TestCase;

/** This file: vouchers at checkout (partial / full cover, races, replays). */
class CheckoutControllerVoucherTest extends TestCase
{
    use CheckoutControllerFixtures;
    use RefreshDatabase;

    /**
     * ADR-024 decision #1/#4 — feeBase (and therefore the gateway-
     * charged fee) is computed on the cash portion only, never the
     * voucher-covered portion. Decision #1: redemption happens after
     * the gateway confirms success, so it's already reflected by the
     * time this response returns.
     */
    public function test_partial_cover_voucher_reduces_fee_base_and_redeems_after_gateway_success(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = $this->voucher(['remaining' => 200]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['voucher_code' => $voucher->code]));

        $response->assertCreated();
        $response->assertJsonPath('payment_status', 'pending');

        $order = Order::query()->firstOrFail();
        $this->assertSame($voucher->id, $order->voucher_id);
        $this->assertSame(200, $order->voucher_discount);
        // feeBase = 500 - 200 = 300; flat_fee_sen = 210 from activeChannel().
        $this->assertSame(210, $order->transaction_fee);
        $this->assertSame(510, $order->final_amount);
        $this->assertNotNull($order->payment_ref);

        $this->assertSame(0, $voucher->fresh()->remaining);
        $this->assertSame('exhausted', $voucher->fresh()->status);
        $redemption = VoucherRedemption::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(200, $redemption->amount);
        $this->assertSame('reserved', $redemption->status);
    }

    /**
     * ADR-024 decision #5 — the real gap this decision closed: a
     * flat-fee channel (activeChannel() seeds flat_fee_sen=210) must
     * not charge that fee at all once the gateway is skipped entirely.
     */
    public function test_full_cover_voucher_skips_gateway_and_settles_immediately(): void
    {
        Queue::fake();
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = $this->voucher(['remaining' => 1000]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['voucher_code' => $voucher->code]));

        $response->assertCreated();
        $response->assertJsonPath('payment_status', 'paid');
        $response->assertJsonPath('payment_actions', []);

        $order = Order::query()->firstOrFail();
        $this->assertSame($voucher->id, $order->voucher_id);
        $this->assertSame(500, $order->voucher_discount); // capped at selling_price, not the full 1000 remaining
        $this->assertSame(0, $order->transaction_fee);
        $this->assertSame(0, $order->final_amount);
        $this->assertNull($order->payment_ref);
        $this->assertSame('paid', $order->payment_status->value);

        $this->assertSame(500, $voucher->fresh()->remaining); // 1000 - 500, not fully exhausted

        Queue::assertPushed(
            FulfillOrderJob::class,
            fn ($job) => $job->order->id === $order->id,
        );
    }

    /**
     * 2026-09-29 pre-release review: since M-6 a full-cover order can sit
     * at Pending (mid-redeem) or Failed (lost the voucher race) with
     * payment_ref null. A replayed idempotency key must never send that
     * RM0 order to the gateway.
     */
    public function test_a_replayed_full_cover_checkout_never_reaches_the_gateway(): void
    {
        Queue::fake();
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $voucher = $this->voucher(['remaining' => 1000]);
        $payload = $this->payload($game, $package, ['voucher_code' => $voucher->code]);

        $this->postJson('/api/checkout', $payload)->assertCreated();
        $order = Order::query()->firstOrFail();
        $order->update(['payment_status' => 'failed']); // lost the M-6 redeem race

        $replay = $this->postJson('/api/checkout', $payload);

        // ADR-024 2026-10-04 addendum decision 6: a Failed order is closed.
        $replay->assertUnprocessable();
        $replay->assertJsonPath('code', 'checkout_closed');
        $this->assertNull($order->fresh()->payment_ref);
    }

    /**
     * ADR-024 2026-10-04 addendum: a concurrent order drains the voucher
     * after the unlocked preview. The customer gets a coded 422 and never
     * the payment link; the order stays visible as Failed.
     */
    public function test_a_checkout_that_loses_the_voucher_race_returns_checkout_closed(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = $this->voucher(['remaining' => 200]);
        Order::created(fn () => Voucher::query()->whereKey($voucher->id)->update(['remaining' => 0, 'status' => 'exhausted']));

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['voucher_code' => $voucher->code]));

        $response->assertUnprocessable();
        $response->assertJsonPath('code', 'checkout_closed');
        $response->assertJsonMissingPath('payment_actions');
        $this->assertSame('failed', Order::query()->firstOrFail()->payment_status->value);
        $this->assertSame(0, VoucherRedemption::query()->count());
    }

    /**
     * 2026-09-29 pre-release review: an unexpected failure inside the
     * voucher lock (lock-wait timeout, deadlock) used to roll back
     * payment_ref along with it — orphaning a live CHIP purchase the
     * webhook could never match. payment_ref must survive.
     */
    public function test_a_voucher_lock_failure_after_the_gateway_call_keeps_the_payment_ref(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = $this->voucher(['remaining' => 200]); // partial cover — real gateway leg

        DB::beforeExecuting(function (string $query) {
            if (str_starts_with(strtolower($query), 'insert into "voucher_redemptions"')) {
                throw new RuntimeException('Lock wait timeout exceeded');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/checkout', $this->payload($game, $package, ['voucher_code' => $voucher->code]));
            $this->fail('the simulated lock failure should surface');
        } catch (RuntimeException $e) {
            $this->assertSame('Lock wait timeout exceeded', $e->getMessage());
        }

        $this->assertSame('pr-checkout-test', Order::query()->firstOrFail()->payment_ref);
    }

    /**
     * ORD-9/ADR-024 decision #3 — an invalid code (here: right code,
     * wrong customer) is rejected before any Order is created, with
     * the same generic message VoucherService::preview() always uses.
     */
    public function test_rejects_checkout_with_a_voucher_that_does_not_belong_to_this_customer(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $voucher = $this->voucher(['customer_email' => 'someone-else@example.com']);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['voucher_code' => $voucher->code]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('voucher_code');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1000, $voucher->fresh()->remaining);
    }
}
