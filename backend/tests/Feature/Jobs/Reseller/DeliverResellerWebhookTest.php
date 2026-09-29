<?php

namespace Tests\Feature\Jobs\Reseller;

use App\Jobs\Reseller\DeliverResellerWebhook;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerWebhookDelivery;
use App\Services\Reseller\Webhook\ResellerWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-084 PR-3 decision 4: signs `X-Hub-Signature-256`, records every
 * attempt on the delivery row, exhausts after ~5 tries.
 */
class DeliverResellerWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeOutboundDns();
    }

    private function setup_delivery(string $event = 'order.delivered'): ResellerWebhookDelivery
    {
        $reseller = Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true]);
        $this->secret = app(ResellerWebhookService::class)->setEndpoint($reseller, 'https://example.test/hook')['secret'];

        $order = Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'wallet_reseller_id' => $reseller->id,
            'order_number' => 'PG-'.uniqid(),
            'customer_email' => 'buyer@example.test',
            'player_id' => '123456',
            'cost_price' => 900, 'standard_selling_price' => 900, 'selling_price' => 1000,
            'transaction_fee' => 0, 'final_amount' => 1000,
            'platform_profit' => 100, 'affiliate_profit' => 0,
            'payment_status' => 'paid', 'delivery_status' => 'delivered',
        ]);

        return ResellerWebhookDelivery::query()->create([
            'reseller_id' => $reseller->id, 'order_id' => $order->id,
            'event' => $event, 'event_id' => 'evt-1',
            'payload' => ['order_number' => $order->order_number, 'event' => $event, 'event_id' => 'evt-1'],
            'status' => 'pending',
        ]);
    }

    public function test_a_2xx_marks_the_delivery_delivered_and_signs_the_body(): void
    {
        $delivery = $this->setup_delivery();
        Http::fake(['https://example.test/hook' => Http::response('', 200)]);

        (new DeliverResellerWebhook($delivery->id))->handle();

        $delivery->refresh();
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->last_response_code);
        $this->assertNull($delivery->next_retry_at);

        Http::assertSent(function ($request) use ($delivery) {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), $this->secret);

            return $request->header('X-Hub-Signature-256')[0] === $expected
                && $request->header('X-Webhook-Event')[0] === 'order.delivered'
                && $request->header('X-Webhook-Id')[0] === $delivery->event_id;
        });
    }

    public function test_a_5xx_records_the_attempt_and_throws_to_retry(): void
    {
        $delivery = $this->setup_delivery();
        Http::fake(['https://example.test/hook' => Http::response('nope', 503)]);

        try {
            (new DeliverResellerWebhook($delivery->id))->handle();
            $this->fail('Expected the job to throw so the queue retries.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(503, $delivery->last_response_code);
        $this->assertNotNull($delivery->next_retry_at);
    }

    public function test_failed_hook_marks_the_delivery_exhausted(): void
    {
        $delivery = $this->setup_delivery();

        (new DeliverResellerWebhook($delivery->id))->failed(new \RuntimeException('gave up'));

        $this->assertSame('exhausted', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->next_retry_at);
    }

    public function test_an_inactive_endpoint_fails_the_delivery_without_throwing(): void
    {
        $delivery = $this->setup_delivery();
        $delivery->reseller->webhook->update(['is_active' => false]);
        Http::fake();

        (new DeliverResellerWebhook($delivery->id))->handle();

        $this->assertSame('failed', $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * Wave 3 S-3: the URL was checked when it was saved, but DNS can be
     * repointed afterwards (rebinding). Re-checked at send time; a
     * non-public target is terminal, not retried.
     */
    public function test_an_endpoint_now_resolving_to_a_private_address_is_never_called(): void
    {
        $delivery = $this->setup_delivery();
        $this->fakeOutboundDns(['example.test' => ['169.254.169.254']]);
        Http::fake();

        (new DeliverResellerWebhook($delivery->id))->handle();

        Http::assertNothingSent();
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->next_retry_at);
    }

    /**
     * 2026-09-29 pre-release review: a host that didn't resolve at all
     * (resolver hiccup, DNS outage) is transient, not a non-public target
     * — it must go through the normal retry/backoff, not be dropped.
     */
    public function test_an_endpoint_that_fails_to_resolve_is_retried_not_dropped(): void
    {
        $delivery = $this->setup_delivery();
        $this->fakeOutboundDns(['example.test' => []]);
        Http::fake();

        try {
            (new DeliverResellerWebhook($delivery->id))->handle();
            $this->fail('Expected the job to throw so the queue retries.');
        } catch (\RuntimeException $e) {
            // expected
        }

        Http::assertNothingSent();
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->next_retry_at);
    }

    public function test_a_redirect_is_not_followed(): void
    {
        $delivery = $this->setup_delivery();
        Http::fake([
            'https://example.test/hook' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
            '*' => Http::response('secret-metadata', 200),
        ]);

        try {
            (new DeliverResellerWebhook($delivery->id))->handle();
        } catch (\RuntimeException) {
            // a 3xx is a non-2xx: recorded + retried like any other
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
        $this->assertSame(302, $delivery->fresh()->last_response_code);
    }

    public function test_an_already_delivered_row_is_a_noop(): void
    {
        $delivery = $this->setup_delivery();
        $delivery->update(['status' => 'delivered', 'attempts' => 1]);
        Http::fake();

        (new DeliverResellerWebhook($delivery->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(1, $delivery->fresh()->attempts);
    }
}
