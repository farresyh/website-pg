<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Reseller\Bot\ResellerBotReplyFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-093 decision 4 — orderPlaced() now echoes the player ID (and
 * server ID, when present) back to the reseller, for every order, not
 * just validator-covered games: the single strongest human-error catch
 * available for a fat-fingered ID, at zero added latency or cost.
 */
class ResellerBotReplyFormatterTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $overrides = []): Order
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my', 'reseller_code' => 'MLMY', 'is_active' => true]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'cost_price' => 1000, 'standard_selling_price' => 1200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);

        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-TEST1',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'customer_email' => 'wallet-order@pekangame.space',
            'player_id' => '51049607',
            'server_id' => '2005',
            'cost_price' => 1000,
            'standard_selling_price' => 1200,
            'selling_price' => 1200,
            'transaction_fee' => 0,
            'final_amount' => 1200,
            'platform_profit' => 200,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    public function test_order_placed_echoes_the_player_id_and_server_id(): void
    {
        $order = $this->makeOrder();

        $reply = ResellerBotReplyFormatter::orderPlaced($order);

        $this->assertStringContainsString('51049607', $reply);
        $this->assertStringContainsString('2005', $reply);
    }

    public function test_order_placed_echoes_the_player_id_alone_when_there_is_no_server_id(): void
    {
        $order = $this->makeOrder(['server_id' => null]);

        $reply = ResellerBotReplyFormatter::orderPlaced($order);

        $this->assertStringContainsString('51049607', $reply);
    }
}
