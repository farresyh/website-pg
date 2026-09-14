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
use Illuminate\Support\Collection;
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

    /**
     * @param  int  $count  How many packages to fake for `.list` — real
     *                      catalogue sizes that trip `MAX_MESSAGE_LENGTH`: MLID (79), MLMY
     *                      (59), MLGB (43) — found 2026-09-13 grepping every reseller-coded
     *                      game's `.list` reply length, all three silently dropped by
     *                      OpenWA before this fix.
     * @return Collection<int, array{code: string, package: Package}>
     */
    private function makeListItems(Game $game, int $count): Collection
    {
        return collect(range(1, $count))->map(fn (int $n) => [
            'code' => "{$game->reseller_code}-{$n}",
            'package' => new Package([
                'name' => "{$n} Diamonds",
                'cost_price' => 1000 + $n,
                'standard_selling_price' => 1100 + $n,
            ]),
        ]);
    }

    public function test_list_packages_stays_a_single_message_under_the_cap(): void
    {
        $game = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my', 'reseller_code' => 'MLMY', 'is_active' => true]);
        $items = $this->makeListItems($game, 5);

        $reply = ResellerBotReplyFormatter::listPackages($game, $items, fn (Package $p) => $p->standard_selling_price);

        $this->assertCount(1, $reply);
        // No "(1/1)" clutter — a game short enough to fit renders
        // identically to before this method could ever split.
        $this->assertStringNotContainsString('(1/1)', $reply[0]);
        $this->assertStringContainsString('MLMY-1', $reply[0]);
        $this->assertStringContainsString('Guna .order {kod}', $reply[0]);
    }

    public function test_list_packages_splits_a_long_catalogue_across_multiple_messages(): void
    {
        $game = Game::query()->create(['name' => 'Mobile Legends Indonesia', 'slug' => 'mlbb-id', 'reseller_code' => 'MLID', 'is_active' => true]);
        $items = $this->makeListItems($game, 79); // MLID's real 2026-09-13 count

        $reply = ResellerBotReplyFormatter::listPackages($game, $items, fn (Package $p) => $p->standard_selling_price);

        $this->assertGreaterThan(1, count($reply));

        foreach ($reply as $chunk) {
            $this->assertLessThanOrEqual(ResellerBotReplyFormatter::MAX_MESSAGE_LENGTH, strlen($chunk));
        }

        // Every package code appears exactly once across all chunks —
        // nothing dropped, nothing duplicated, no split mid-package.
        foreach (range(1, 79) as $n) {
            $occurrences = collect($reply)->filter(fn (string $chunk) => str_contains($chunk, "MLID-{$n}\n"))->count();
            $this->assertSame(1, $occurrences, "MLID-{$n} should appear in exactly one chunk");
        }

        // Part label on every chunk, footer only on the last.
        $total = count($reply);
        foreach ($reply as $index => $chunk) {
            $this->assertStringContainsString('('.($index + 1)."/{$total})", $chunk);
            $isLast = $index === $total - 1;
            $this->assertSame($isLast, str_contains($chunk, 'Guna .order {kod}'));
        }
    }
}
