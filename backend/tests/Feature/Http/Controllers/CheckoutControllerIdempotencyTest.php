<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Order;
use App\Models\Package;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\Support\CheckoutControllerFixtures;
use Tests\TestCase;

/** This file: idempotency-key replay and resume. */
class CheckoutControllerIdempotencyTest extends TestCase
{
    use CheckoutControllerFixtures;
    use RefreshDatabase;

    /**
     * ADR-019's checkout-level idempotency fix — the actual scenario
     * this exists for: a customer double-click or client-side timeout
     * retry re-sends the exact same request. Must return the same
     * order, not create (and pay for) a second one.
     */
    public function test_replays_the_same_order_for_a_repeated_idempotency_key_after_success(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/checkout', $this->payload($game, $package, ['idempotency_key' => $key]));
        $first->assertCreated();

        $second = $this->postJson('/api/checkout', $this->payload($game, $package, ['idempotency_key' => $key]));
        $second->assertOk(); // 200 — a replay, not a newly-created resource
        $second->assertJsonPath('order_number', $first->json('order_number'));

        $this->assertSame(1, Order::query()->count());
    }

    /**
     * The other real branch: the first attempt's payment call failed
     * (Order created, no payment_ref), and the retry with the same key
     * must resume that same Order rather than starting a fresh one.
     */
    public function test_resumes_the_existing_order_when_a_repeated_key_previously_failed_payment(): void
    {
        $this->bindGateway(createSucceeds: false);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/checkout', $this->payload($game, $package, ['idempotency_key' => $key]));
        $first->assertUnprocessable();
        $this->assertSame(1, Order::query()->count());
        $this->assertNull(Order::query()->firstOrFail()->payment_ref);

        $this->rebindGateway(createSucceeds: true);
        $second = $this->postJson('/api/checkout', $this->payload($game, $package, ['idempotency_key' => $key]));

        $second->assertOk();
        $this->assertSame(1, Order::query()->count());
        $this->assertSame('pr-checkout-test', Order::query()->firstOrFail()->payment_ref);
    }

    /**
     * Item 63 (2026-10-04): a replay must be the same request. Reusing a key
     * with a different channel (or package, voucher, player) used to return
     * the first order — priced with the first channel's fee — as if it were
     * this one. Same payload-hash rule the Reseller API already applies.
     */
    public function test_a_replayed_key_with_a_different_channel_is_rejected(): void
    {
        $this->bindGateway();
        $this->activeChannel('FPX_OTHER', ['flat_fee_sen' => 50]);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $key = (string) Str::uuid();

        $this->postJson('/api/checkout', $this->payload($game, $package, ['idempotency_key' => $key]))->assertCreated();

        $replay = $this->postJson('/api/checkout', $this->payload($game, $package, ['idempotency_key' => $key, 'channel_code' => 'FPX_OTHER']));

        $replay->assertUnprocessable();
        $replay->assertJsonPath('code', 'idempotency_mismatch');
        $this->assertSame(1, Order::query()->count());
    }

    public function test_an_identical_replay_still_returns_the_same_order(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $payload = $this->payload($game, $package);

        $first = $this->postJson('/api/checkout', $payload)->assertCreated();
        // Contact details are not part of what is bought: editing a name
        // between a timed-out submit and its retry is still the same order.
        $replay = $this->postJson('/api/checkout', array_merge($payload, ['customer_name' => 'Edited Name']));

        $replay->assertOk()->assertJsonPath('order_number', $first->json('order_number'));
        $this->assertNotNull(Order::query()->firstOrFail()->idempotency_payload_hash);
    }

    public function test_a_different_idempotency_key_creates_a_genuinely_separate_order(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package))->assertCreated();
        $this->postJson('/api/checkout', $this->payload($game, $package))->assertCreated();

        $this->assertSame(2, Order::query()->count());
    }

    /**
     * Decision 6: replaying the same idempotency key after the race was
     * lost must not hand out the existing link either.
     */
    public function test_a_replayed_failed_checkout_with_a_link_returns_checkout_closed(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $payload = $this->payload($game, $package);

        $this->postJson('/api/checkout', $payload)->assertCreated();
        Order::query()->firstOrFail()->update(['payment_status' => 'failed']);

        $replay = $this->postJson('/api/checkout', $payload);

        $replay->assertUnprocessable();
        $replay->assertJsonPath('code', 'checkout_closed');
        $replay->assertJsonMissingPath('payment_actions');
    }
}
