<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Supplier\SupplierResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComboFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-094 decisions 7-11, 18-20 (Phase 3, core — synchronous legs
 * only; Digiflazz's async Pending-then-webhook per-leg resolution is a
 * separate follow-up phase, not built here).
 *
 * This file: combo profit and real-cost reconciliation, per pricing basis.
 */
class OrderFulfillmentServiceComboProfitTest extends TestCase
{
    use ComboFulfillmentFixtures;
    use RefreshDatabase;

    /**
     * Same idempotency-by-construction guard finalizePendingDelivery()
     * relies on at the order level — a duplicate webhook delivery for
     * an already-finalized leg throws, never double-credits.
     */
    /**
     * ADR-107 decision 2 — platform_profit is reconciled to the
     * money-conservation residual on final delivery: order.selling_price
     * (frozen) − Σ live componentPackage->cost_price across every leg −
     * affiliate_profit. Both components here default to cost_price=500,
     * so live cost total = 1000; order fixture selling_price=1500,
     * affiliate_profit=0 -> expected platform_profit = 500 (overriding
     * the fixture's own hardcoded 200).
     */
    public function test_platform_profit_reconciles_to_the_money_conservation_residual_on_full_delivery(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $b = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo, ['affiliate_profit' => 0]);

        $adapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 5.0]),
            SupplierResponse::success(['supplier_ref' => 'SREF-B', 'price' => 5.0]),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame(500, $result->platform_profit);
        $this->assertSame(500, LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->sole()->amount);
    }

    /**
     * ADR-107 decision 2 — reconciliation reads LIVE cost at final
     * resolution, not the cost each leg had when it individually
     * delivered: leg B's component cost_price is bumped (simulating a
     * routine SyncSupplierPricesJob run) between the first attempt and
     * the retry that finally delivers it, and the platform absorbs the
     * difference in its own reported profit.
     */
    public function test_platform_profit_reconciliation_reflects_a_live_cost_change_between_attempts(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId); // cost_price 500
        $b = $this->componentPackage($supplier, $gameId); // cost_price 500
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo, ['affiliate_profit' => 0]);

        $firstAttemptAdapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 5.0]),
            SupplierResponse::failure('insufficient_balance', 'No balance'),
        ]);
        $firstResult = $this->service($firstAttemptAdapter)->fulfill($order);
        $this->assertSame(DeliveryStatus::PartiallyDelivered, $firstResult->delivery_status);

        // A price sync lands between the first attempt and the retry —
        // package B's real cost has genuinely risen.
        $b->update(['cost_price' => 700]);

        $retryAdapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-B-RETRY', 'price' => 7.0]),
        ]);
        $secondResult = $this->service($retryAdapter)->fulfill($firstResult->fresh());

        $this->assertSame(DeliveryStatus::Delivered, $secondResult->delivery_status);
        // selling_price 1500 - (real cost 500 + 700) - affiliate_profit 0 = 300
        $this->assertSame(300, $secondResult->platform_profit);
    }

    /**
     * ADR-107 decision 3, signal generalized by ADR-111 decision 7 — a
     * resulting loss is never blocked: the order still delivers, the
     * reconciled (negative) platform_profit is recorded as-is, and
     * Order::hasReconciledProfitFlag() (the Order Detail visibility
     * signal, universal now — not combo-only) becomes true. No
     * override-reason gate (unlike ADR-105 decision 4's synchronous-
     * admin-only mechanism, deliberately not reused here).
     */
    public function test_a_resulting_loss_never_blocks_delivery_and_flags_the_order(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId, ['cost_price' => 1000]);
        $b = $this->componentPackage($supplier, $gameId, ['cost_price' => 1000]);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        // selling_price 1500 stays below the 2000 total live cost.
        $order = $this->paidComboOrder($combo, ['affiliate_profit' => 0]);

        $adapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 10.0]),
            SupplierResponse::success(['supplier_ref' => 'SREF-B', 'price' => 10.0]),
        ]);

        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame(-500, $result->platform_profit);
        $this->assertTrue($result->hasReconciledProfitFlag());
        // The customer still got their goods, and the ledger still
        // credits whatever was reconciled — a real, logged loss, not a
        // silently clamped-to-zero one.
        $this->assertSame(-500, LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->sole()->amount);
    }

    /**
     * ADR-107 decision 2 — affiliate_profit is never touched by
     * reconciliation, even when the platform absorbs a real cost
     * increase: it keeps the exact value frozen at checkout.
     */
    public function test_affiliate_profit_is_never_reconciled_by_a_combo_retry(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [['package' => $a, 'quantity' => 1]]);
        $order = $this->paidComboOrder($combo, ['affiliate_profit' => 50, 'selling_price' => 900]);

        $adapter = $this->queuedAdapter([SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 5.0])]);
        $result = $this->service($adapter)->fulfill($order);

        $this->assertSame(50, $result->affiliate_profit);
        // selling_price 900 - liveCost 500 - affiliate_profit 50 = 350
        $this->assertSame(350, $result->platform_profit);
    }

    /**
     * ADR-111 decision 2 — the leg-scoped capture: attemptLeg()'s own
     * Success branch converts the supplier's real price into
     * `real_cost_price_sen`, unconditionally (regardless of the feature
     * flag — only resolveComboOutcome()'s own USE of it is gated).
     */
    public function test_attempt_leg_captures_real_cost_price_sen_on_delivery(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId);
        $b = $this->componentPackage($supplier, $gameId);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo);

        $adapter = $this->queuedAdapter([
            SupplierResponse::success(['supplier_ref' => 'SREF-A', 'price' => 7.0]),
            SupplierResponse::success(['supplier_ref' => 'SREF-B', 'price' => 4.8]),
        ]);
        $this->service($adapter)->fulfill($order);

        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $this->assertSame(700, $legs[0]->real_cost_price_sen);
        $this->assertSame(480, $legs[1]->real_cost_price_sen);
    }

    /**
     * ADR-111 decision 3/8: when the flag is enabled, resolveComboOutcome()
     * sums each leg's REAL cost instead of its catalog `cost_price` — and
     * falls back to that leg's own catalog cost only when ITS real cost
     * is genuinely missing (decision 6's per-delivery fallback), never
     * failing the whole reconciliation over one leg. Legs are pre-seeded
     * directly as already-Delivered so fulfill()'s combo loop skips
     * attemptLeg() entirely and goes straight to resolveComboOutcome() —
     * same direct-model pattern this file already uses for
     * isPartialComboDelivery() coverage above.
     */
    public function test_resolve_combo_outcome_uses_real_cost_with_per_leg_fallback(): void
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId, ['cost_price' => 500]);
        $b = $this->componentPackage($supplier, $gameId, ['cost_price' => 500]);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo, ['selling_price' => 1500, 'affiliate_profit' => 0, 'platform_profit' => 100]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $a->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'SREF-1',
            'selling_price_sen' => $a->standard_selling_price, 'real_cost_price_sen' => 700,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $b->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'SREF-2',
            // real_cost_price_sen genuinely missing (FX unavailable at
            // capture time) — falls back to this leg's own catalog cost.
            'selling_price_sen' => $b->standard_selling_price, 'real_cost_price_sen' => null,
        ]);

        $result = $this->service($this->queuedAdapter([]))->fulfill($order);

        // costTotal = 700 (real, leg A) + 500 (catalog fallback, leg B) = 1200
        // platformProfit = 1500 - 1200 - 0 = 300; drift from the 100 estimate = 200 (> RM1 AND > 1% of 1500).
        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame(300, $result->platform_profit);
        $this->assertTrue($result->profit_reconciled_flagged);
    }

    /**
     * ADR-111 decision 9, extended to combo: resolveComboOutcome()'s own
     * aggregation formula never branches on `pricing_basis` (same
     * basis-agnostic identity as the non-combo case — ADR-105 decision
     * 8's precedent) — proven directly for all 4 bases a combo order can
     * legitimately carry (Standard/guest, Affiliate wholesale, Member,
     * Reseller Wallet), not just assumed from the non-combo coverage in
     * OrderFulfillmentServiceTest.
     */
    public function test_resolve_combo_outcome_reconciles_correctly_for_standard_pricing_basis(): void
    {
        $this->assertComboReconciliationHoldsForBasis('standard', affiliateProfit: 0);
    }

    public function test_resolve_combo_outcome_reconciles_correctly_for_affiliate_pricing_basis(): void
    {
        $this->assertComboReconciliationHoldsForBasis('affiliate', affiliateProfit: 120);
    }

    public function test_resolve_combo_outcome_reconciles_correctly_for_member_pricing_basis(): void
    {
        $this->assertComboReconciliationHoldsForBasis('member', affiliateProfit: 0);
    }

    public function test_resolve_combo_outcome_reconciles_correctly_for_reseller_wallet_pricing_basis(): void
    {
        $this->assertComboReconciliationHoldsForBasis('reseller-wallet', affiliateProfit: 0);
    }
}
