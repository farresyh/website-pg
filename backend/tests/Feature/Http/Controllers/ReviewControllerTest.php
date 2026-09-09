<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\Order;
use App\Models\Review;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Review\ReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-053 — public, guest review submission. Mirrors
 * TrackOrderControllerTest's order-creation helper.
 */
class ReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
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

    public function test_can_submit_a_review_for_a_delivered_order(): void
    {
        $order = $this->order(['order_number' => 'KRS-REV1']);

        $response = $this->postJson("/api/orders/{$order->order_number}/review", [
            'rating' => 5,
            'comment' => 'Fast delivery, thanks!',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('reviews', [
            'order_id' => $order->id,
            'rating' => 5,
            'comment' => 'Fast delivery, thanks!',
            'status' => ReviewStatus::Pending->value,
        ]);
    }

    public function test_comment_is_optional(): void
    {
        $order = $this->order(['order_number' => 'KRS-REV2']);

        $this->postJson("/api/orders/{$order->order_number}/review", ['rating' => 4])
            ->assertCreated();

        $this->assertDatabaseHas('reviews', ['order_id' => $order->id, 'rating' => 4, 'comment' => null]);
    }

    public function test_rating_must_be_between_1_and_5(): void
    {
        $order = $this->order(['order_number' => 'KRS-REV3']);

        $this->postJson("/api/orders/{$order->order_number}/review", ['rating' => 6])
            ->assertUnprocessable();
        $this->postJson("/api/orders/{$order->order_number}/review", ['rating' => 0])
            ->assertUnprocessable();
    }

    public function test_rejects_a_review_for_an_order_that_is_not_delivered(): void
    {
        $order = $this->order(['order_number' => 'KRS-REV4', 'delivery_status' => DeliveryStatus::Processing->value]);

        $this->postJson("/api/orders/{$order->order_number}/review", ['rating' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_rejects_a_second_review_for_the_same_order(): void
    {
        $order = $this->order(['order_number' => 'KRS-REV5']);
        Review::query()->create(['order_id' => $order->id, 'rating' => 5]);

        $this->postJson("/api/orders/{$order->order_number}/review", ['rating' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_returns_404_for_an_unknown_order_number(): void
    {
        $this->primaryAffiliate();

        $this->postJson('/api/orders/KRS-DOES-NOT-EXIST/review', ['rating' => 5])
            ->assertNotFound()
            ->assertJson(['message' => 'No order found with that order number.']);
    }

    public function test_does_not_require_authentication(): void
    {
        $order = $this->order(['order_number' => 'KRS-REV6']);

        $this->postJson("/api/orders/{$order->order_number}/review", ['rating' => 5])->assertCreated();
    }

    public function test_cannot_review_an_order_placed_on_another_brands_storefront(): void
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme Resell',
            'markup_pct' => 10,
            'status' => 'active',
        ]);
        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id,
            'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Active,
            'is_primary' => true,
        ]);
        $order = $this->order(['order_number' => 'KRS-ACME-REV', 'affiliate_id' => $affiliate->id]);

        // Submitted against the primary storefront (no header) — must 404,
        // and no review is written.
        $this->postJson("/api/orders/{$order->order_number}/review", ['rating' => 5])
            ->assertNotFound();
        $this->assertDatabaseCount('reviews', 0);

        // Same order, its own storefront — allowed.
        $this->postJson("/api/orders/{$order->order_number}/review", ['rating' => 5], ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertCreated();
    }
}
