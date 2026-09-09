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

        $supplier = \App\Models\Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => [],
            'currency' => 'MYR',
        ]);

        $package = \App\Models\Package::query()->create([
            'game_id' => $game->id,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'REF-86',
            'name' => '86 Diamonds',
            'cost_price' => 500,
            'standard_selling_price' => 600,
            'is_active' => true,
        ]);

        $order1 = $this->order([
            'customer_name' => 'Siti Nurhaliza',
            'game_id' => $game->id,
            'package_id' => $package->id,
        ]);
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
            'package_name' => '86 Diamonds',
        ]);
    }

    public function test_game_reviews_returns_scoped_reviews_and_aggregates(): void
    {
        $game = Game::query()->create([
            'name' => 'Valorant',
            'slug' => 'valorant',
            'is_active' => true,
        ]);

        $supplier = \App\Models\Supplier::query()->create([
            'name' => 'Supplier A',
            'slug' => 'supplier-a',
            'api_config' => [],
            'currency' => 'MYR',
        ]);

        $package = \App\Models\Package::query()->create([
            'game_id' => $game->id,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'REF-VAL-1',
            'name' => '1000 VP',
            'cost_price' => 3000,
            'standard_selling_price' => 3500,
            'is_active' => true,
        ]);

        $primaryAffiliate = $this->primaryAffiliate();

        $order1 = $this->order([
            'affiliate_id' => $primaryAffiliate->id,
            'game_id' => $game->id,
            'package_id' => $package->id,
            'customer_name' => 'Farres Haikal',
        ]);
        $order2 = $this->order([
            'affiliate_id' => $primaryAffiliate->id,
            'game_id' => $game->id,
            'package_id' => $package->id,
            'customer_name' => 'Amirul Asyraf',
        ]);
        // Order for a different game
        $otherGame = Game::query()->create(['name' => 'Other Game', 'slug' => 'other-game', 'is_active' => true]);
        $orderOtherGame = $this->order([
            'affiliate_id' => $primaryAffiliate->id,
            'game_id' => $otherGame->id,
            'customer_name' => 'Other Buyer',
        ]);

        Review::query()->create([
            'order_id' => $order1->id,
            'rating' => 5,
            'comment' => 'Fast delivery 2 seconds!',
            'status' => ReviewStatus::Approved->value,
        ]);
        Review::query()->create([
            'order_id' => $order2->id,
            'rating' => 4,
            'comment' => 'Trusted seller.',
            'status' => ReviewStatus::Approved->value,
        ]);
        Review::query()->create([
            'order_id' => $orderOtherGame->id,
            'rating' => 5,
            'comment' => 'Unrelated game review.',
            'status' => ReviewStatus::Approved->value,
        ]);

        $response = $this->getJson('/api/catalog/games/valorant/reviews');

        $response->assertOk();
        $response->assertJsonPath('average_rating', 4.5);
        $response->assertJsonPath('review_count', 2);
        $response->assertJsonCount(2, 'reviews');
        $response->assertJsonFragment([
            'name' => 'Farres H.',
            'rating' => 5,
            'comment' => 'Fast delivery 2 seconds!',
            'package_name' => '1000 VP',
        ]);
    }

    public function test_game_reviews_strictly_scopes_by_affiliate_domain(): void
    {
        $game = Game::query()->create([
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'is_active' => true,
        ]);

        $primaryAffiliate = $this->primaryAffiliate();

        $affiliateB = \App\Models\Affiliate::query()->create([
            'business_name' => 'Soloz Store',
            'markup_pct' => 5,
            'status' => 'active',
        ]);
        \App\Models\AffiliateDomain::query()->create([
            'affiliate_id' => $affiliateB->id,
            'hostname' => 'solozstore.my',
            'status' => \App\Services\Affiliate\AffiliateDomainStatus::Active,
            'is_primary' => true,
        ]);

        // Review under Primary
        $orderPrimary = $this->order([
            'affiliate_id' => $primaryAffiliate->id,
            'game_id' => $game->id,
            'customer_name' => 'Buyer Primary',
        ]);
        Review::query()->create([
            'order_id' => $orderPrimary->id,
            'rating' => 5,
            'comment' => 'Primary platform review',
            'status' => ReviewStatus::Approved->value,
        ]);

        // Review under Affiliate B
        $orderAffiliateB = $this->order([
            'affiliate_id' => $affiliateB->id,
            'game_id' => $game->id,
            'customer_name' => 'Buyer Soloz',
        ]);
        Review::query()->create([
            'order_id' => $orderAffiliateB->id,
            'rating' => 4,
            'comment' => 'Soloz store review only',
            'status' => ReviewStatus::Approved->value,
        ]);

        // Request without host -> resolves primary
        $resPrimary = $this->getJson('/api/catalog/games/mobile-legends/reviews');
        $resPrimary->assertOk();
        $resPrimary->assertJsonPath('review_count', 1);
        $resPrimary->assertJsonPath('reviews.0.name', 'Buyer P.');
        $resPrimary->assertJsonPath('reviews.0.comment', 'Primary platform review');

        // Request with affiliate host -> strictly resolves affiliate B
        $resAffiliateB = $this->getJson('/api/catalog/games/mobile-legends/reviews', [
            'X-Storefront-Host' => 'solozstore.my',
        ]);
        $resAffiliateB->assertOk();
        $resAffiliateB->assertJsonPath('review_count', 1);
        $resAffiliateB->assertJsonPath('reviews.0.name', 'Buyer S.');
        $resAffiliateB->assertJsonPath('reviews.0.comment', 'Soloz store review only');
    }

    public function test_game_reviews_404s_for_inactive_or_nonexistent_game(): void
    {
        Game::query()->create([
            'name' => 'Inactive Game',
            'slug' => 'inactive-game',
            'is_active' => false,
        ]);

        $this->getJson('/api/catalog/games/inactive-game/reviews')->assertNotFound();
        $this->getJson('/api/catalog/games/non-existent-game/reviews')->assertNotFound();
    }
}
