<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\PriceChangeLog;
use App\Models\Supplier;
use App\Models\SupplierGroupLink;
use App\Models\SupplierProduct;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-137 decision 21: when a supplier changes system, an existing package
 * is pointed at the new supplier product. It keeps its id, history, markup,
 * name and combos; its cost comes from the new price; it stays as it was
 * (on or off); the change is recorded.
 */
class SupplierProductRepointTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($this->admin);
        $this->supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $this->game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire']);
    }

    private AdminUser $admin;

    private function product(array $overrides = []): SupplierProduct
    {
        SupplierGroupLink::query()->firstOrCreate(
            ['supplier_id' => $this->supplier->id, 'group_label' => 'Free Fire (Malaysia)'],
            ['game_id' => $this->game->id],
        );

        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $this->supplier->id, 'external_ref' => 'ff_my:100',
            'name' => '100 Diamonds', 'group_label' => 'Free Fire (Malaysia)', 'price_sen' => 450,
            'status_raw' => 'active', 'last_synced_at' => now(),
        ], $overrides));
    }

    private function package(array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $this->game->id, 'name' => 'Free Fire 100 Diamonds', 'denomination' => 100, 'catalog_code' => null,
            'cost_price' => 400, 'markup_percent' => 20, 'standard_selling_price' => 480,
            'is_active' => false, 'deactivated_reason' => 'supplier_issue', 'deactivated_at' => now(),
            'supplier_id' => $this->supplier->id, 'supplier_package_ref' => '15116',
        ], $overrides));
    }

    private function repoint(SupplierProduct $product, Package $package)
    {
        return $this->postJson("/api/middleware/supplier-products/{$product->id}/repoint", ['package_id' => $package->id]);
    }

    public function test_repoint_moves_the_package_to_the_new_reference_and_prices_it_from_the_new_product(): void
    {
        $package = $this->package();
        $product = $this->product();

        $this->repoint($product, $package)->assertOk()->assertJsonPath('package.supplier_package_ref', 'ff_my:100');

        $package->refresh();
        $this->assertSame('ff_my:100', $package->supplier_package_ref);
        $this->assertSame(450, $package->cost_price);
        $this->assertSame(540, $package->standard_selling_price); // 450 × 1.20, markup kept
        $this->assertSame('Free Fire 100 Diamonds', $package->name);
        $this->assertFalse($package->is_active);
        $this->assertDatabaseHas('package_ref_changes', [
            'package_id' => $package->id, 'old_ref' => '15116', 'new_ref' => 'ff_my:100', 'admin_user_id' => $this->admin->id,
        ]);
        $this->assertSame(1, PriceChangeLog::query()->where('package_id', $package->id)->where('admin_user_id', $this->admin->id)->count());
    }

    public function test_repoint_closes_an_open_price_swing_that_priced_the_old_reference(): void
    {
        $package = $this->package();
        $swing = PendingPriceChange::query()->create([
            'package_id' => $package->id, 'old_cost_price' => 400, 'proposed_cost_price' => 900,
            'old_standard_selling_price' => 480, 'proposed_standard_selling_price' => 1080, 'status' => 'pending',
        ]);

        $this->repoint($this->product(), $package)->assertOk();

        $this->assertSame('superseded', $swing->fresh()->status);
    }

    public function test_repoint_refuses_a_product_already_in_the_catalog(): void
    {
        $this->package(['supplier_package_ref' => 'ff_my:100']);

        $this->repoint($this->product(), $this->package())->assertUnprocessable();
    }

    public function test_repoint_refuses_another_supplier_another_game_or_a_combo(): void
    {
        $product = $this->product();
        $other = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $otherGame = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);

        $this->repoint($product, $this->package(['supplier_id' => $other->id]))->assertUnprocessable();
        $this->repoint($product, $this->package(['game_id' => $otherGame->id, 'supplier_package_ref' => 'x2']))->assertUnprocessable();
        $this->repoint($product, $this->package(['is_combo' => true, 'supplier_id' => null, 'supplier_package_ref' => null]))->assertUnprocessable();
    }

    public function test_repoint_refuses_a_product_whose_group_is_not_linked_to_the_package_game(): void
    {
        $this->repoint($this->product(['group_label' => 'Not Linked']), $this->package())->assertUnprocessable();
    }

    public function test_repoint_refuses_while_an_order_on_the_package_is_unsettled(): void
    {
        $package = $this->package();
        Order::query()->create(['placed_via' => 'storefront', 'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-RP-1', 'reference_number' => 'REF-RP-1', 'customer_email' => 'b@example.com', 'player_id' => '1',
            'package_id' => $package->id, 'supplier_id' => $this->supplier->id, 'supplier_product_ref' => '15116',
            'cost_price' => 400, 'standard_selling_price' => 480, 'selling_price' => 480, 'transaction_fee' => 0, 'final_amount' => 480,
            'platform_profit' => 80, 'affiliate_profit' => 0, 'payment_status' => 'paid', 'delivery_status' => DeliveryStatus::Pending->value]);

        $this->repoint($this->product(), $package)->assertUnprocessable();
        $this->assertSame('15116', $package->fresh()->supplier_package_ref);
    }

    public function test_repoint_refuses_a_product_that_is_not_active(): void
    {
        $package = $this->package();

        $this->repoint($this->product(['status_raw' => 'inactive']), $package)->assertUnprocessable();

        $this->assertSame('15116', $package->fresh()->supplier_package_ref);
    }

    private function order(Package $package, string $payment, string $delivery): Order
    {
        $n = uniqid();

        return Order::query()->create(['placed_via' => 'storefront', 'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => "KRS-{$n}", 'reference_number' => "REF-{$n}", 'customer_email' => 'b@example.com', 'player_id' => '1',
            'package_id' => $package->id, 'supplier_id' => $this->supplier->id, 'supplier_product_ref' => '15116',
            'cost_price' => 400, 'standard_selling_price' => 480, 'selling_price' => 480, 'transaction_fee' => 0, 'final_amount' => 480,
            'platform_profit' => 80, 'affiliate_profit' => 0, 'payment_status' => $payment, 'delivery_status' => $delivery]);
    }

    /** ADR-138 decision 19: its frozen old reference would be sent after the move. */
    public function test_repoint_refuses_an_order_paid_or_awaiting_payment_and_not_yet_sent(): void
    {
        foreach (['paid', 'pending'] as $payment) {
            $package = $this->package(['supplier_package_ref' => "OLD-{$payment}"]);
            $this->order($package, $payment, DeliveryStatus::NotStarted->value);

            $this->repoint($this->product(['external_ref' => "ff_my:{$payment}"]), $package)->assertUnprocessable();
        }
    }

    /** A failed payment leaves not_started behind for good; it must not block forever. */
    public function test_repoint_ignores_failed_payments_and_finished_deliveries(): void
    {
        $package = $this->package();
        $this->order($package, 'failed', DeliveryStatus::NotStarted->value);
        $this->order($package, 'paid', DeliveryStatus::Delivered->value);
        $this->order($package, 'paid', DeliveryStatus::Failed->value);

        $this->repoint($this->product(), $package)->assertOk();
    }

    /** A combo leg sends its component's live reference, so a combo order counts as an order on the component. */
    public function test_repoint_refuses_while_a_combo_holding_the_package_has_an_order_not_yet_sent(): void
    {
        $package = $this->package();
        $combo = $this->package(['name' => 'Combo', 'is_combo' => true, 'supplier_id' => null, 'supplier_package_ref' => null]);
        $combo->components()->attach($package->id, ['quantity' => 1, 'sort_order' => 0]);
        $this->order($combo, 'paid', DeliveryStatus::NotStarted->value);

        $this->repoint($this->product(), $package)->assertUnprocessable();
    }

    public function test_the_products_list_marks_a_repointed_product_as_in_the_catalog(): void
    {
        $product = $this->product();
        $this->repoint($product, $this->package())->assertOk();

        $this->getJson("/api/middleware/supplier-products?supplier_id={$this->supplier->id}")
            ->assertOk()->assertJsonPath('data.0.is_promoted', true);
    }
}
