<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\Order;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\OrderFulfillmentFixtures;
use Tests\TestCase;

/** This file: supplier drawdowns and ADR-111 real-cost profit reconciliation. */
class OrderFulfillmentServiceCostTest extends TestCase
{
    use OrderFulfillmentFixtures;
    use RefreshDatabase;

    /**
     * ADR-083 decision 3: a synchronous Success (Gamevion always, or a
     * Digiflazz order that never went Pending) writes ORDER_DRAWDOWN
     * from the adapter's own `price` — AFTER fulfill()'s transaction
     * commits, never from `orders.cost_price`.
     */
    public function test_fulfill_records_a_supplier_ledger_drawdown_when_the_adapter_reports_a_price(): void
    {
        $order = $this->paidOrder();

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1', 'price' => 850.0]))
            ->fulfill($order);

        $entry = SupplierLedgerEntry::query()->sole();
        $this->assertSame(SupplierLedgerEntryType::OrderDrawdown->value, $entry->type);
        $this->assertSame('-850.0000', $entry->amount);
        $this->assertSame('MYR', $entry->currency);
        $this->assertSame('order', $entry->reference_type);
        $this->assertSame($order->id, $entry->reference_id);
    }

    /** A Pending response never carries a price — nothing to record yet. */
    public function test_fulfill_records_no_drawdown_when_the_adapter_reports_no_price(): void
    {
        $order = $this->paidOrder();

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-2']))
            ->fulfill($order);

        $this->assertSame(0, SupplierLedgerEntry::query()->count());
    }

    /** A Pending order accepted (but not yet finalized) records no drawdown either. */
    public function test_fulfill_records_no_drawdown_on_a_pending_response(): void
    {
        $order = $this->paidOrder();

        $this->service($this->fakePendingSupplierAdapter())->fulfill($order);

        $this->assertSame(0, SupplierLedgerEntry::query()->count());
    }

    /**
     * Defense-in-depth (ADR-083 decision 2's own append-only guard is
     * the primary one): SupplierFundingService::recordOrderDrawdown()
     * itself won't double-write for the same order even if called twice.
     */
    public function test_record_order_drawdown_is_idempotent_per_order(): void
    {
        $order = $this->paidOrder();
        $funding = app(SupplierFundingService::class);

        $funding->recordOrderDrawdown($order, 850.0);
        $funding->recordOrderDrawdown($order, 850.0);

        $this->assertSame(1, SupplierLedgerEntry::query()->count());
    }

    /**
     * ADR-111 decision 2: real cost is captured on EVERY successful
     * delivery regardless of the feature flag — only whether it's USED
     * to recompute platform_profit is gated (decision 8).
     */
    public function test_fulfill_captures_real_cost_price_sen_but_leaves_platform_profit_untouched_when_flag_disabled(): void
    {
        config(['services.real_cost_reconciliation.enabled' => false]);
        $order = $this->paidOrder(['platform_profit' => 100]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'REF-1', 'price' => 8.5]))
            ->fulfill($order);

        $this->assertSame(850, $result->real_cost_price_sen);
        $this->assertSame(100, $result->platform_profit);
        $this->assertFalse($result->profit_reconciled_flagged);
    }

    /**
     * ADR-111 decision 3/4: when the flag is enabled, EVERY successful
     * delivery (not just a retry) recomputes platform_profit as the
     * basis-agnostic residual — closing the gap the ADR's own Context
     * called out as the biggest latent one (a plain first delivery never
     * reconciled against anything beyond the checkout-time estimate).
     */
    public function test_fulfill_reconciles_platform_profit_with_real_cost_when_flag_enabled(): void
    {
        config(['services.real_cost_reconciliation.enabled' => true]);
        $order = $this->paidOrder(['selling_price' => 1000, 'affiliate_profit' => 0, 'platform_profit' => 100]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'REF-1', 'price' => 8.5]))
            ->fulfill($order);

        $this->assertSame(850, $result->real_cost_price_sen);
        // 1000 - 850 - 0 = 150
        $this->assertSame(150, $result->platform_profit);
    }

    /**
     * ADR-111 decision 7: the universal signal fires on a material drift
     * from the pre-reconciliation estimate (more than RM1 AND more than
     * 1% of selling_price) even when the reconciled result is still
     * positive — not just on an outright loss.
     */
    public function test_fulfill_flags_a_material_positive_profit_drift_when_flag_enabled(): void
    {
        config(['services.real_cost_reconciliation.enabled' => true]);
        $order = $this->paidOrder(['selling_price' => 1000, 'affiliate_profit' => 0, 'platform_profit' => 100]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'REF-1', 'price' => 2.0]))
            ->fulfill($order);

        // real_cost 200 sen -> profit 800, drift from the 100 estimate = 700 (> RM1 AND > 1% of 1000).
        $this->assertSame(800, $result->platform_profit);
        $this->assertTrue($result->profit_reconciled_flagged);
    }

    /**
     * ADR-111 decision 6: a genuinely unavailable FX rate (no live fetch
     * succeeds, nothing was ever stored for the pair) never blocks
     * delivery — falls back to today's existing catalog-cost estimate
     * for this one delivery, real_cost_price_sen stays null.
     */
    public function test_fulfill_falls_back_to_catalog_profit_when_fx_rate_is_unavailable(): void
    {
        config(['services.real_cost_reconciliation.enabled' => true]);
        Http::fake(['open.er-api.com/*' => Http::response(['message' => 'Server error'], 500)]);
        // Pre-create the default test supplier with a non-MYR currency —
        // paidOrder()'s own firstOrCreate() then finds this row instead
        // of minting a fresh MYR one, so the adapter stays bound under
        // the same slug service() already binds it to.
        Supplier::query()->create(['name' => 'Test Supplier', 'slug' => self::DEFAULT_SUPPLIER_SLUG, 'api_config' => [], 'currency' => 'IDR']);
        $order = $this->paidOrder(['platform_profit' => 100]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'REF-1', 'price' => 15000.0]))
            ->fulfill($order);

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertNull($result->real_cost_price_sen);
        $this->assertSame(100, $result->platform_profit);
        $this->assertFalse($result->profit_reconciled_flagged);
    }

    /**
     * ADR-111 decision 9: explicit per-pricing_basis coverage — the
     * residual formula (decision 3) never branches on `pricing_basis`
     * itself, so `affiliate_profit` (whatever each basis already
     * determined) must stay exactly as-is across every one of the four.
     */
    public function test_fulfill_reconciles_correctly_for_standard_pricing_basis(): void
    {
        $this->assertReconciliationHoldsForBasis('standard', affiliateProfit: 0);
    }

    public function test_fulfill_reconciles_correctly_for_affiliate_pricing_basis(): void
    {
        $this->assertReconciliationHoldsForBasis('affiliate', affiliateProfit: 120);
    }

    public function test_fulfill_reconciles_correctly_for_member_pricing_basis(): void
    {
        $this->assertReconciliationHoldsForBasis('member', affiliateProfit: 0);
    }

    public function test_fulfill_reconciles_correctly_for_reseller_wallet_pricing_basis(): void
    {
        // ADR-111 decision 3 — a ResellerWallet order's own affiliate_profit
        // equivalent is always 0 by construction; its entire markup is
        // platform_profit, so it carries the most exposure to real-cost drift.
        $this->assertReconciliationHoldsForBasis('reseller-wallet', affiliateProfit: 0);
    }
}
