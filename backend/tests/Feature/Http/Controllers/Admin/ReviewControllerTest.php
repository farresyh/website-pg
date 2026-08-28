<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Order;
use App\Models\Review;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Review\ReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ], $overrides));
    }

    private function review(array $overrides = []): Review
    {
        $order = $overrides['order_id'] ?? null;

        if ($order === null) {
            $order = $this->order()->id;
        }

        return Review::query()->create(array_merge([
            'order_id' => $order,
            'rating' => 5,
        ], $overrides, ['order_id' => $order]));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/reviews')->assertUnauthorized();
    }

    public function test_index_returns_stats_and_paginated_reviews(): void
    {
        $this->actingAsAdmin();
        $this->review(['status' => ReviewStatus::Pending->value]);
        $this->review(['status' => ReviewStatus::Approved->value, 'rating' => 4]);
        $this->review(['status' => ReviewStatus::Rejected->value]);

        $response = $this->getJson('/api/reviews');

        $response->assertOk();
        $response->assertJsonPath('stats.pending', 1);
        $response->assertJsonPath('stats.approved', 1);
        $response->assertJsonPath('stats.rejected', 1);
        $response->assertJsonPath('stats.total', 3);
        $this->assertEquals(4.0, $response->json('stats.average_rating'));
        $this->assertCount(3, $response->json('reviews.data'));
    }

    public function test_average_rating_is_scoped_to_approved_only(): void
    {
        $this->actingAsAdmin();
        $this->review(['status' => ReviewStatus::Approved->value, 'rating' => 5]);
        // A 1-star rejected review would drag the average down if it
        // weren't excluded — decision 7.
        $this->review(['status' => ReviewStatus::Rejected->value, 'rating' => 1]);

        $this->assertEquals(5.0, $this->getJson('/api/reviews')->json('stats.average_rating'));
    }

    public function test_status_filter_does_not_affect_the_stats_block(): void
    {
        $this->actingAsAdmin();
        $this->review(['status' => ReviewStatus::Pending->value]);
        $this->review(['status' => ReviewStatus::Approved->value]);

        $response = $this->getJson('/api/reviews?status=pending');

        $response->assertJsonPath('stats.pending', 1);
        $response->assertJsonPath('stats.approved', 1);
        $this->assertCount(1, $response->json('reviews.data'));
    }

    public function test_with_comment_only_filter(): void
    {
        $this->actingAsAdmin();
        $this->review(['comment' => 'Great service']);
        $this->review(['comment' => null]);

        $response = $this->getJson('/api/reviews?with_comment_only=1');

        $this->assertCount(1, $response->json('reviews.data'));
    }

    public function test_admin_can_approve_a_review(): void
    {
        $this->actingAsAdmin();
        $review = $this->review(['status' => ReviewStatus::Pending->value]);

        $this->patchJson("/api/reviews/{$review->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    public function test_admin_can_reject_a_review(): void
    {
        $this->actingAsAdmin();
        $review = $this->review(['status' => ReviewStatus::Pending->value]);

        $this->patchJson("/api/reviews/{$review->id}/reject")
            ->assertOk()
            ->assertJsonPath('status', 'rejected');
    }

    public function test_bulk_approve_only_touches_pending_reviews(): void
    {
        $this->actingAsAdmin();
        $this->review(['status' => ReviewStatus::Pending->value]);
        $this->review(['status' => ReviewStatus::Pending->value]);
        $alreadyRejected = $this->review(['status' => ReviewStatus::Rejected->value]);

        $response = $this->postJson('/api/reviews/bulk-approve');

        $response->assertOk();
        $response->assertJsonPath('approved_count', 2);
        $this->assertSame(ReviewStatus::Rejected, $alreadyRejected->fresh()->status);
    }

    public function test_regular_admin_can_moderate_reviews(): void
    {
        // REV-1..5 sits at the same super_admin,admin tier as
        // Orders/Vouchers (PRD §3) — unlike Blacklist/Middleware.
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/reviews')->assertOk();
    }
}
