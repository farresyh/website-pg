<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Game;
use App\Models\Order;
use App\Models\Review;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Review\ReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewCatalogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-'.uniqid(),
            'customer_name' => 'Ahmad Razak',
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

    public function test_returns_only_approved_reviews_with_comments(): void
    {
        $game = Game::query()->create([
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'is_active' => true,
        ]);

        $order1 = $this->order(['customer_name' => 'Siti Nurhaliza', 'game_id' => $game->id]);
        $order2 = $this->order(['customer_name' => 'John Doe', 'game_id' => $game->id]);
        $order3 = $this->order(['customer_name' => 'Ali Baba', 'game_id' => $game->id]);

        // Approved with comment -> should be returned
        Review::query()->create([
            'order_id' => $order1->id,
            'rating' => 5,
            'comment' => 'Sangat laju dan mantap!',
            'status' => ReviewStatus::Approved->value,
        ]);

        // Pending review -> should not be returned
        Review::query()->create([
            'order_id' => $order2->id,
            'rating' => 5,
            'comment' => 'Masih pending review',
            'status' => ReviewStatus::Pending->value,
        ]);

        // Approved but empty comment -> should not be returned
        Review::query()->create([
            'order_id' => $order3->id,
            'rating' => 5,
            'comment' => null,
            'status' => ReviewStatus::Approved->value,
        ]);

        $response = $this->getJson('/api/catalog/reviews');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'name' => 'Siti N.',
            'rating' => 5,
            'comment' => 'Sangat laju dan mantap!',
            'game_name' => 'Mobile Legends',
        ]);
    }
}
