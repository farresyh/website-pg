<?php

namespace Tests\Feature\Models;

use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-106 2026-11-02 addendum: what a delivered unit (order or combo leg)
 * cost against what it was expected to cost at checkout, shown on the
 * attempt row that delivered it. Read from the facts that already exist
 * (the unit's cost, the unit's `initial` attempt), never stored twice.
 */
class OrderCostVsCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-COST-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 305,
            'standard_selling_price' => 327,
            'selling_price' => 327,
            'transaction_fee' => 3,
            'final_amount' => 330,
            'platform_profit' => 23,
            'affiliate_profit' => 0,
            'real_cost_price_sen' => 304,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ], $overrides));
    }

    private function attempt(Order $order, string $type, string $outcome, int $costSen, ?int $legId = null): OrderResendAttempt
    {
        return OrderResendAttempt::query()->create([
            'order_id' => $order->id,
            'order_delivery_leg_id' => $legId,
            'attempt_type' => $type,
            'cost_price_sen' => $costSen,
            'standard_selling_price_sen' => 327,
            'outcome' => $outcome,
        ]);
    }

    /** @return array<int, array{cost_vs_checkout_sen: int, cost_basis: string}> */
    private function byAttempt(Order $order): array
    {
        return collect(Order::query()->findOrFail($order->id)->costVsCheckout())->keyBy('attempt_id')
            ->map(fn (array $row) => ['cost_vs_checkout_sen' => $row['cost_vs_checkout_sen'], 'cost_basis' => $row['cost_basis']])
            ->all();
    }

    public function test_a_first_attempt_delivery_compares_the_real_cost_with_the_checkout_cost(): void
    {
        $order = $this->order(['cost_price' => 197, 'selling_price' => 217, 'standard_selling_price' => 217, 'real_cost_price_sen' => 200, 'platform_profit' => 17]);
        $initial = $this->attempt($order, 'initial', 'success', 197);

        $this->assertSame([$initial->id => ['cost_vs_checkout_sen' => 3, 'cost_basis' => 'real']], $this->byAttempt($order));
    }

    /** PG-WSEZAO6R4TGU: bought as a 297-sen package, delivered by a resend to a 305-sen one that really cost 304. */
    public function test_a_package_swap_resend_compares_the_real_cost_with_the_original_checkout_cost(): void
    {
        $order = $this->order();
        $this->attempt($order, 'initial', 'failed', 297);
        $this->attempt($order, 'resend', 'failed', 297);
        $delivering = $this->attempt($order, 'resend', 'success', 305);

        $this->assertSame([$delivering->id => ['cost_vs_checkout_sen' => 7, 'cost_basis' => 'real']], $this->byAttempt($order));
    }

    /** A manual supplier's delivery has two success rows; the admin's confirmation, which set the cost, carries it. */
    public function test_a_manual_confirmation_carries_the_figure_not_the_resolved_post(): void
    {
        $order = $this->order(['cost_price' => 50, 'selling_price' => 60, 'standard_selling_price' => 60, 'real_cost_price_sen' => 52, 'platform_profit' => 8]);
        $this->attempt($order, 'initial', 'success', 50);
        $confirm = $this->attempt($order, 'manual_confirm', 'success', 50);

        $this->assertSame([$confirm->id => ['cost_vs_checkout_sen' => 2, 'cost_basis' => 'real']], $this->byAttempt($order));
    }

    /** Delivered before real cost was captured (ADR-111): the catalog figure, labelled as such. */
    public function test_an_order_without_a_real_cost_reports_the_catalog_figure_as_estimated(): void
    {
        $order = $this->order(['real_cost_price_sen' => null, 'platform_profit' => 22]);
        $this->attempt($order, 'initial', 'failed', 297);
        $delivering = $this->attempt($order, 'resend', 'success', 305);

        $this->assertSame([$delivering->id => ['cost_vs_checkout_sen' => 8, 'cost_basis' => 'estimated']], $this->byAttempt($order));
    }

    public function test_nothing_is_reported_before_delivery_or_without_a_checkout_baseline(): void
    {
        $failed = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'real_cost_price_sen' => null]);
        $this->attempt($failed, 'initial', 'failed', 305);

        $noBaseline = $this->order();
        $this->attempt($noBaseline, 'resend', 'success', 305);

        $this->assertSame([], $this->byAttempt($failed));
        $this->assertSame([], $this->byAttempt($noBaseline));
    }

    /** ADR-106 decision 5's deferral closed: each delivered leg against its own first attempt; a leg with no real cost shows nothing. */
    public function test_each_combo_leg_with_a_real_cost_is_compared_with_its_own_first_attempt(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia']);
        $component = Package::query()->create([
            'game_id' => $game->id, 'name' => '86 Diamonds', 'denomination' => 86, 'cost_price' => 500,
            'standard_selling_price' => 550, 'markup_percent' => 10, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-86',
        ]);
        $order = $this->order(['cost_price' => 1000, 'selling_price' => 1100, 'standard_selling_price' => 1100, 'real_cost_price_sen' => null, 'platform_profit' => 95]);
        $leg = fn (int $n, ?int $real) => OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $component->id, 'supplier_id' => $supplier->id,
            'leg_number' => $n, 'status' => DeliveryStatus::Delivered->value, 'selling_price_sen' => 550, 'real_cost_price_sen' => $real,
        ]);
        $realLeg = $leg(1, 505);
        $catalogLeg = $leg(2, null);
        $this->attempt($order, 'initial', 'failed', 500, $realLeg->id);
        $delivering = $this->attempt($order, 'retry', 'success', 500, $realLeg->id);
        $this->attempt($order, 'initial', 'success', 500, $catalogLeg->id);

        $this->assertSame([$delivering->id => ['cost_vs_checkout_sen' => 5, 'cost_basis' => 'real']], $this->byAttempt($order));
    }
}
