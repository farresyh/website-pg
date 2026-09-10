<?php

namespace Tests\Feature\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Review;
use App\Models\Supplier;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'server_id' => '2005',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ], $overrides));
    }

    public function test_returns_the_customer_safe_fields_for_a_known_order_number(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '86 Diamonds', 'cost_price' => 421, 'standard_selling_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        $order = $this->order(['order_number' => 'KRS-TEST123', 'game_id' => $game->id, 'package_id' => $package->id]);

        $response = $this->getJson("/api/track-order/{$order->order_number}");

        $response->assertOk();
        $response->assertJson([
            'order_number' => 'KRS-TEST123',
            'game' => ['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia'],
            'package_name' => '86 Diamonds',
            'player_id' => '123456',
            'server_id' => '2005',
            'final_amount' => 1100,
            'payment_status' => 'paid',
            'delivery_status' => 'delivered',
        ]);
    }

    public function test_never_exposes_internal_financial_or_supplier_fields(): void
    {
        $order = $this->order([
            'order_number' => 'KRS-PRIVACY',
            'customer_name' => 'Ahmad Danish',
            'customer_email' => 'ahmad.danish@example.com',
            'customer_phone' => '0123456789',
            'payment_ref' => 'xnd_req_SECRET123',
        ]);

        $response = $this->getJson("/api/track-order/{$order->order_number}");

        $response->assertOk();
        $keys = array_keys($response->json());
        foreach (['cost_price', 'standard_selling_price', 'platform_profit', 'affiliate_profit', 'supplier_response', 'payment_ref', 'supplier_ref', 'customer_name', 'customer_email', 'customer_phone'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "leaked $forbidden");
        }

        // ADR-065: the raw contact values must never appear anywhere in the body.
        $body = $response->getContent();
        $this->assertStringNotContainsString('ahmad.danish@example.com', $body);
        $this->assertStringNotContainsString('0123456789', $body);
        $this->assertStringNotContainsString('Ahmad Danish', $body);
        $this->assertStringNotContainsString('xnd_req_SECRET123', $body);
    }

    public function test_returns_masked_contact_and_the_customer_facing_payment_breakdown(): void
    {
        $order = $this->order([
            'order_number' => 'KRS-MASK',
            'customer_name' => 'Ahmad Danish',
            'customer_email' => 'ahmad.danish@example.com',
            'customer_phone' => '0123456789',
            'payment_method' => 'Touch \'n Go eWallet',
            'selling_price' => 1090,
            'voucher_discount' => 100,
            'transaction_fee' => 100,
            'final_amount' => 1090,
        ]);

        $this->getJson("/api/track-order/{$order->order_number}")
            ->assertOk()
            ->assertJson([
                'customer_name_masked' => 'Ahmad D.',
                'customer_email_masked' => 'a••••@example.com',
                'customer_phone_masked' => '01•-•••-•789',
                'payment_method' => 'Touch \'n Go eWallet',
                'selling_price' => 1090,
                'voucher_discount' => 100,
                'transaction_fee' => 100,
                'final_amount' => 1090,
            ]);
    }

    public function test_broadcast_payload_matches_the_endpoint_shape(): void
    {
        $order = $this->order([
            'order_number' => 'KRS-BROADCAST',
            'customer_name' => 'Ahmad Danish',
            'customer_email' => 'ahmad.danish@example.com',
            'customer_phone' => '0123456789',
        ])->fresh(['game', 'package', 'review']);

        $endpoint = $this->getJson("/api/track-order/{$order->order_number}")->json();
        $broadcast = (new OrderStatusUpdated($order))->broadcastWith();

        $this->assertSame($endpoint, $broadcast);
    }

    public function test_returns_404_for_an_unknown_order_number(): void
    {
        // No header → StorefrontBrand falls back to Affiliate::primary(),
        // which requires the row to exist (ADR-061).
        $this->primaryAffiliate();

        $response = $this->getJson('/api/track-order/KRS-DOES-NOT-EXIST');

        $response->assertNotFound();
        $response->assertJson(['message' => 'No order found with that order number.']);
    }

    public function test_does_not_require_authentication(): void
    {
        $order = $this->order();

        $this->getJson("/api/track-order/{$order->order_number}")->assertOk();
    }

    public function test_has_review_is_false_when_no_review_exists(): void
    {
        $order = $this->order(['order_number' => 'KRS-NOREVIEW']);

        $this->getJson("/api/track-order/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('has_review', false);
    }

    public function test_has_review_is_true_once_a_review_exists(): void
    {
        $order = $this->order(['order_number' => 'KRS-HASREVIEW']);
        Review::query()->create(['order_id' => $order->id, 'rating' => 5]);

        $this->getJson("/api/track-order/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('has_review', true);
    }

    private function affiliateWithDomain(string $hostname): Affiliate
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme Resell',
            'markup_pct' => 10,
            'status' => 'active',
        ]);
        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id,
            'hostname' => $hostname,
            'status' => AffiliateDomainStatus::Active,
            'is_primary' => true,
        ]);

        return $affiliate;
    }

    public function test_an_affiliate_storefront_order_is_not_visible_on_the_primary_storefront(): void
    {
        $affiliate = $this->affiliateWithDomain('shop.acme.com');
        $order = $this->order(['order_number' => 'KRS-ACME', 'affiliate_id' => $affiliate->id]);

        // No header → primary brand. The order belongs to shop.acme.com.
        $this->getJson("/api/track-order/{$order->order_number}")
            ->assertNotFound()
            ->assertJson(['message' => 'No order found with that order number.']);
    }

    public function test_an_affiliate_storefront_order_resolves_on_its_own_storefront(): void
    {
        $affiliate = $this->affiliateWithDomain('shop.acme.com');
        $order = $this->order(['order_number' => 'KRS-ACME-OK', 'affiliate_id' => $affiliate->id]);

        $this->getJson("/api/track-order/{$order->order_number}", ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertOk()
            ->assertJsonPath('order_number', 'KRS-ACME-OK');
    }

    public function test_a_primary_storefront_order_is_not_visible_on_an_affiliate_storefront(): void
    {
        $this->affiliateWithDomain('shop.acme.com');
        // order() defaults affiliate_id to the primary affiliate.
        $order = $this->order(['order_number' => 'KRS-PRIMARY-ONLY']);

        $this->getJson("/api/track-order/{$order->order_number}", ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertNotFound();
    }
}
