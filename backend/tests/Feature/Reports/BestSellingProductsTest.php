<?php

namespace Tests\Feature\Reports;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderDraft;
use App\Services\Order\OrderFactory;
use App\Services\Order\PaymentStatus;
use App\Services\Order\PlacedVia;
use App\Services\Pricing\PricingBasis;
use App\Services\Pricing\PricingResolution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-104 2026-11-03 addendum (PRD §16 item 94): one definition of a
 * product, snapshotted on the order at creation, and the best-selling
 * products breakdown in Reports.
 */
class BestSellingProductsTest extends TestCase
{
    use RefreshDatabase;

    private Game $mlbb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mlbb = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mlbb']);
    }

    private function package(array $attributes): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $this->mlbb->id, 'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
        ], $attributes));
    }

    private function order(Package $package, int $finalAmount, array $overrides = []): Order
    {
        $order = Order::query()->create(array_merge(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'game_id' => $package->game_id,
            'package_id' => $package->id,
            'product_key' => $package->productKey(),
            'product_name' => $package->name,
            'cost_price' => 500,
            'standard_selling_price' => 600,
            'selling_price' => $finalAmount,
            'transaction_fee' => 0,
            'final_amount' => $finalAmount,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
            'delivery_status' => DeliveryStatus::Delivered->value,
            'recognised_at' => now(),
            'is_test' => false,
        ], $overrides));
        (new LedgerService)->credit('platform', null, $order->platform_profit, 'order_profit', 'order', $order->id);

        return $order;
    }

    public function test_a_product_is_the_storefronts_equivalence_key(): void
    {
        $id = $this->mlbb->id;

        $this->assertSame("{$id}:d:14", $this->package(['name' => '14 Diamonds', 'denomination' => 14])->productKey());
        $this->assertSame("{$id}:c:WEEKLY", $this->package(['name' => 'Weekly Pass', 'catalog_code' => 'WEEKLY'])->productKey());
        $loose = $this->package(['name' => 'Starlight']);
        $this->assertSame("{$id}:p:{$loose->id}", $loose->productKey());
    }

    public function test_the_order_factory_snapshots_the_product_and_a_resend_swap_leaves_it(): void
    {
        $bought = $this->package(['name' => '14 Diamonds (A)', 'denomination' => 14]);
        $order = app(OrderFactory::class)->create(new OrderDraft(
            pricing: new PricingResolution(costPriceSen: 500, standardSellingPriceSen: 600, sellingPriceSen: 600, platformProfitSen: 100, affiliateProfitSen: 0, basis: PricingBasis::Standard),
            idempotencyKey: 'idem-94',
            customerEmail: 'buyer@example.test',
            customerName: 'Buyer',
            customerPhone: '0100000000',
            playerId: '123456',
            serverId: null,
            affiliateId: $this->primaryAffiliate()->id,
            paymentStatus: PaymentStatus::Pending,
            paidAt: null,
            paymentMethod: 'fpx',
            placedVia: PlacedVia::Storefront,
            gameId: $this->mlbb->id,
            packageId: $bought->id,
        ));

        $this->assertSame("{$this->mlbb->id}:d:14", $order->product_key);
        $this->assertSame('14 Diamonds (A)', $order->product_name);

        $order->update(['package_id' => $this->package(['name' => 'Other SKU', 'denomination' => 50])->id]);
        $this->assertSame("{$this->mlbb->id}:d:14", $order->fresh()->product_key);
    }

    public function test_the_backfill_uses_the_package_bought_at_checkout(): void
    {
        $bought = $this->package(['name' => '14 Diamonds', 'denomination' => 14]);
        $swappedTo = $this->package(['name' => '56 Diamonds', 'denomination' => 56]);
        $swapped = $this->order($swappedTo, 600, ['product_key' => null, 'product_name' => null]);
        OrderResendAttempt::query()->create(['order_id' => $swapped->id, 'attempt_type' => 'initial', 'package_id' => $bought->id, 'cost_price_sen' => 500, 'standard_selling_price_sen' => 600, 'outcome' => 'failed']);
        $old = $this->order($bought, 600, ['product_key' => null, 'product_name' => null]);
        $gone = $this->order($this->package(['name' => 'Gone', 'denomination' => 99]), 600, ['product_key' => null, 'product_name' => null]);
        DB::table('packages')->where('id', $gone->package_id)->delete();
        $goneId = $gone->fresh()->package_id; // an FK may null it, as sqlite's does; production keeps the id

        $migration = require database_path('migrations/2026_11_03_120000_add_product_key_to_orders_table.php');
        $migration->backfill();
        $migration->backfill();

        $this->assertSame(["{$this->mlbb->id}:d:14", '14 Diamonds'], [$swapped->fresh()->product_key, $swapped->fresh()->product_name]);
        $this->assertSame("{$this->mlbb->id}:d:14", $old->fresh()->product_key);
        $gone->refresh();
        $this->assertSame("{$this->mlbb->id}:p:".($goneId ?? 0), $gone->product_key);
        $this->assertNull($gone->product_name);
    }

    public function test_the_breakdown_groups_by_product_sorted_by_sales_with_game_share_and_profit(): void
    {
        $native = $this->package(['name' => '112 Diamonds', 'denomination' => 112]);
        $combo = $this->package(['name' => '112 Diamonds (combo)', 'denomination' => 112, 'is_combo' => true]);
        $small = $this->package(['name' => '14 Diamonds', 'denomination' => 14]);
        $this->order($native, 3000);
        $this->order($combo, 3000);
        $this->order($small, 1000);
        $this->order($small, 1000, ['payment_status' => PaymentStatus::Pending->value, 'paid_at' => null]);
        $this->order($small, 1000, ['is_test' => true]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $rows = $this->getJson('/api/reports/breakdown/products')->assertOk()->json('products');

        $this->assertCount(2, $rows);
        $this->assertSame(['game_name' => 'Mobile Legends', 'product_name' => '112 Diamonds (combo)', 'sales' => 6000, 'orders_count' => 2, 'platform_profit' => 200, 'pct_of_game_sales' => 85.71],
            array_intersect_key($rows[0], array_flip(['game_name', 'product_name', 'sales', 'orders_count', 'platform_profit', 'pct_of_game_sales'])));
        $this->assertSame(['14 Diamonds', 1000, 1], [$rows[1]['product_name'], $rows[1]['sales'], $rows[1]['orders_count']]);
        $this->assertSame($this->mlbb->id, $rows[0]['game_id']);
    }

    public function test_an_order_with_no_product_name_shows_as_unknown(): void
    {
        $package = $this->package(['name' => 'Gone', 'denomination' => 99]);
        $this->order($package, 500, ['product_name' => null]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/reports/breakdown/products')->assertOk()->assertJsonPath('products.0.product_name', 'Unknown product');
    }
}
