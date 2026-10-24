<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Services\Fulfillment\OrderFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OrderResendFixtures;
use Tests\TestCase;

/**
 * ADR-017: same-game package swap + live price reconciliation on top
 * of OrderFulfillmentService's own untouched fulfill() contract.
 *
 * This file: profit recompute per pricing basis, loss overrides and the ledger credit.
 */
class OrderResendServiceProfitTest extends TestCase
{
    use OrderResendFixtures;
    use RefreshDatabase;

    /**
     * Decision #2: cost_price/standard_selling_price/selling_price/
     * final_amount/transaction_fee are the historical charged record —
     * never rewritten by a resend, same-package or not.
     */
    /**
     * ADR-017 decision 2, revised by ADR-105 2026-10-06 decision 13:
     * what the customer was charged never moves; cost_price follows the
     * package actually delivered.
     */
    public function test_never_touches_what_the_customer_was_charged_and_moves_cost_price_to_the_delivered_package(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        // ADR-105: kept under the order's own standard_selling_price
        // (900, the failedOrder() default) so this resend doesn't trip
        // decision 4's below-cost guard — this test isn't about that.
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 850, 'standard_selling_price' => 850]);
        $order = $this->failedOrder($game, $original, $supplier);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        $this->assertSame(850, $result->cost_price);
        $this->assertSame(900, $result->standard_selling_price);
        $this->assertSame(1000, $result->selling_price);
        $this->assertSame(1100, $result->final_amount);
        $this->assertSame(100, $result->transaction_fee);
    }

    /**
     * Decision #5: platform_profit/affiliate_profit are recomputed from
     * this attempt's live package economics and only actually credited
     * to the ledger when this attempt is the one that succeeds —
     * mirrors OrderFulfillmentService::creditProfit()'s existing
     * "fires exactly once" guarantee, unchanged.
     */
    public function test_credits_the_ledger_with_the_recomputed_profit_for_the_swapped_package(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        // Live cost is now higher than what the original package cost —
        // still safely under the order's own frozen standard_selling_price
        // (1000), so the platform absorbs it out of the existing margin
        // rather than tripping decision 4's below-cost guard.
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 950, 'standard_selling_price' => 1045]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000, 'affiliate_markup_pct' => 0]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        // ADR-105 decision 1: platformProfit = order's OWN frozen
        // standard_selling_price (1000) - live cost (950) = 50 — not the
        // swap package's own live standard_selling_price (1045), which
        // would give a different, un-reconciled 95.
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame(50, $order->fresh()->platform_profit);
    }

    /**
     * ADR-105's own regression case — reproduces the real bug found live
     * on order PG-KWKBUHNDMKQT (order id 15, 2026-09-17): a resend swaps
     * to a package whose own `standard_selling_price` has ALSO
     * independently drifted (a routine price-sync, unrelated to this
     * resend) between the order's checkout and this resend attempt.
     * Pre-fix, profit was computed against that drifted live standard
     * price (392→402 in the real incident) and came out HIGHER despite
     * the platform absorbing more cost. Post-fix, it correctly reflects
     * the order's own frozen price minus the live cost.
     */
    public function test_platform_profit_reconciles_against_the_orders_own_frozen_price_not_the_swapped_packages_drifted_live_price(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        // Mirrors the real order: cost 356, standard/selling 392 at
        // checkout (a ~10% markup).
        $original = $this->package($game, $supplier, ['cost_price' => 356, 'standard_selling_price' => 392]);
        // Mirrors the real swap target: live cost rose to 365 (the real
        // +9 sen absorbed) AND its own standard_selling_price
        // independently drifted 398 -> 402 that same morning, via an
        // unrelated price-sync — the exact drift that decoupled profit
        // from reality pre-fix.
        $swap = $this->package($game, $supplier, [
            'name' => 'PUBGG 60 UC', 'supplier_package_ref' => 'PUBGG_60_PG1',
            'cost_price' => 365, 'standard_selling_price' => 402,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'cost_price' => 356,
            'standard_selling_price' => 392,
            'selling_price' => 392,
            'affiliate_markup_pct' => 0,
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'MG-1']))
            ->resend($order, $swap, null, 'Admin');

        // Pre-fix (the real bug): 402 (swap's live standard) - 365 (live
        // cost) = 37 — profit rose despite absorbing +9 sen of cost.
        // Post-fix: 392 (order's own frozen standard) - 365 (live cost)
        // = 27 — a real 9-sen decrease, matching the absorbed amount 1:1.
        $this->assertSame(27, $order->fresh()->platform_profit);
        $this->assertSame(27, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));

        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame(9, $attempt->price_diff_sen);
    }

    /**
     * ADR-105 decision 4: a resend whose live cost now exceeds what the
     * customer already paid (frozen) is a genuine loss — blocked without
     * an explicit override reason, distinguishing an admin's deliberate
     * "deliver anyway, eat the loss" decision from silent data
     * corruption.
     */
    public function test_resend_that_would_sell_below_the_orders_frozen_price_requires_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        // Live cost (1100) now exceeds the order's frozen standard_selling_price (1000).
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100, 'standard_selling_price' => 1210]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000]);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $swap, null, 'Admin');
    }

    /** Same scenario as above, but with an override reason — proceeds and records the real (negative) loss. */
    public function test_resend_that_would_sell_below_the_orders_frozen_price_proceeds_with_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100, 'standard_selling_price' => 1210]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000, 'affiliate_markup_pct' => 0]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin', overrideReason: 'Customer already paid, deliver anyway per founder instruction');

        // 1000 (frozen) - 1100 (live cost) = -100, a real loss, stored as-is.
        $this->assertSame(-100, $result->platform_profit);
        $this->assertSame(-100, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
    }

    /**
     * ADR-106 addendum (2026-09-21), grill Q7 — `override_reason` used
     * to only ever reach `Log::warning()`, never the durable
     * `order_resend_attempts.note` column, even though it's the exact
     * reason an admin decided to accept a loss and force the resend.
     * Retrofitted for consistency with the new leg/retry attempt rows,
     * which persist it the same way. Only falls back to it when no
     * separate `note` was given — a real `note` always wins.
     */
    public function test_override_reason_falls_back_into_the_attempt_rows_note_when_no_note_given(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100, 'standard_selling_price' => 1210]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000, 'affiliate_markup_pct' => 0]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin', overrideReason: 'Customer already paid, deliver anyway per founder instruction');

        $attempt = OrderResendAttempt::query()->where('order_id', $order->id)->sole();
        $this->assertSame('Customer already paid, deliver anyway per founder instruction', $attempt->note);
    }

    /** A real `note` is never clobbered by an override_reason given alongside it. */
    public function test_a_real_note_is_not_overridden_by_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100, 'standard_selling_price' => 1210]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000, 'affiliate_markup_pct' => 0]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, 'Customer requested a bigger pack', 'Admin', overrideReason: 'Deliver anyway, absorb the loss');

        $attempt = OrderResendAttempt::query()->where('order_id', $order->id)->sole();
        $this->assertSame('Customer requested a bigger pack', $attempt->note);
    }

    /**
     * ADR-105 2026-10-06 addendum, decisions 9 + 11 (reverses decision 3):
     * a member order resent to a dearer package records the residual
     * against what the customer actually paid, not a re-priced member
     * price for the new package. Paid 1000, swap cost 1200 → −200 (the
     * old member formula recorded +36 here), so it needs an override.
     */
    public function test_member_resend_to_a_dearer_package_is_a_loss_that_needs_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 900, 'markup_percent' => 15]);
        $swap = $this->package($game, $supplier, [
            'name' => '210 Diamonds', 'supplier_package_ref' => 'D',
            'cost_price' => 1200, 'markup_percent' => 20, 'standard_selling_price' => 1440,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'member',
            'member_discount_percent' => 80.00,
            'markup_percent' => 15.00,
            'affiliate_profit' => 0,
        ]);

        try {
            $this->service($this->fakeSupplierAdapter(true))->resend($order, $swap, null, 'Admin');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('override_reason', $e->errors());
        }

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order->fresh(), $swap, null, 'Admin', overrideReason: 'Deliver anyway');

        $this->assertSame(-200, $result->platform_profit);
        $this->assertSame(0, $result->affiliate_profit);
    }

    /**
     * Decision 9: a member order's affiliate_profit stays what checkout
     * froze (0, ADR-027) even with a nonzero affiliate_markup_pct on the
     * row — resend never reads a markup.
     */
    public function test_member_resend_keeps_affiliate_profit_and_records_the_residual(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 900, 'markup_percent' => 15]);
        $swap = $this->package($game, $supplier, [
            'name' => '210 Diamonds', 'supplier_package_ref' => 'D',
            'cost_price' => 950, 'markup_percent' => 20, 'standard_selling_price' => 1140,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'member',
            'member_discount_percent' => 80.00,
            'markup_percent' => 15.00,
            'affiliate_markup_pct' => 10.00,
            'affiliate_profit' => 0,
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        $this->assertSame(50, $order->fresh()->platform_profit); // 1000 − 950 − 0
        $this->assertSame(0, $order->fresh()->affiliate_profit);
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     */
    #[DataProvider('basisAndSwapCost')]
    public function test_every_basis_keeps_cost_plus_shares_equal_to_what_the_customer_paid(array $orderOverrides, int $swapCost): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900]);
        $target = $swapCost === 900
            ? $original
            : $this->package($game, $supplier, ['name' => 'Swap', 'supplier_package_ref' => 'S', 'cost_price' => $swapCost, 'standard_selling_price' => 2000, 'markup_percent' => 50]);
        $order = $this->failedOrder($game, $original, $supplier, $orderOverrides);
        $affiliateBefore = $order->affiliate_profit;

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $target, null, 'Admin', overrideReason: 'test');

        $this->assertSame($swapCost, $result->cost_price);
        $this->assertSame($affiliateBefore, $result->affiliate_profit);
        $this->assertSame($result->selling_price, $result->cost_price + $result->affiliate_profit + $result->platform_profit);
        $this->assertSame(
            $result->platform_profit,
            (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'),
        );
    }

    /**
     * ADR-060 PR-4b: a reseller-wallet order's resend recomputes
     * affiliateProfit (always 0 here) at the order's own frozen
     * `wholesale_markup_pct` (the tier rate), not the standard retail
     * chain — the latent bug PR-4b fixed. ADR-105 decision 8: unlike
     * PR-4b's own original assertion, platformProfit itself is now the
     * money-conservation residual (order's frozen selling_price - live
     * cost - affiliateProfit) rather than wholesaleBase - liveCost
     * directly — `selling_price` is set here to what the order's real
     * original checkout would have produced (900 cost * 1.20 tier =
     * 1080, matching wholesaleBase at THAT cost), so this resend's
     * absorbed-cost story is realistic, not an arbitrary leftover
     * default.
     */
    public function test_resend_of_a_reseller_wallet_order_recomputes_at_the_frozen_tier_rate(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 900]);
        $swap = $this->package($game, $supplier, [
            'name' => '210 Diamonds', 'supplier_package_ref' => 'D',
            'cost_price' => 1000, 'standard_selling_price' => 1500,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'reseller-wallet',
            'wholesale_markup_pct' => 20.00,
            'affiliate_markup_pct' => 0,
            'selling_price' => 1080,
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        // affiliateProfit = wholesaleBase(1000*1.20=1200)*0% = 0 — the
        // frozen tier rate, NOT the standard chain (1500-1000=500) the
        // pre-PR-4b `else` branch produced. platformProfit = 1080
        // (frozen) - 1000 (live cost) - 0 = 80.
        $this->assertSame(80, $order->fresh()->platform_profit);
        $this->assertSame(0, $order->fresh()->affiliate_profit);
    }

    /**
     * ADR-060 PR-4b: an affiliate-basis order (PR-4c wires these) resends
     * at frozen `wholesale_markup_pct` + frozen `affiliate_markup_pct`
     * for affiliateProfit. ADR-105 decision 8: `selling_price` set to
     * what original checkout (cost 900) would have actually produced —
     * 900*1.20=1080 wholesale, +10% affiliate markup = 1188 — so
     * platformProfit's residual formula has a realistic frozen total to
     * reconcile against.
     */
    public function test_resend_of_an_affiliate_basis_order_recomputes_at_frozen_wholesale_and_affiliate_markup(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 900]);
        $swap = $this->package($game, $supplier, [
            'name' => '210 Diamonds', 'supplier_package_ref' => 'D',
            'cost_price' => 1000, 'standard_selling_price' => 1500,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'affiliate',
            'wholesale_markup_pct' => 20.00,
            'affiliate_markup_pct' => 10.00,
            'selling_price' => 1188,
            'affiliate_profit' => 108,
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        // ADR-105 2026-10-06 decision 10: affiliateProfit stays the
        // checkout value (1080 × 10% = 108), it no longer scales with the
        // swap's cost. platformProfit = 1188 − 1000 − 108 = 80.
        $this->assertSame(80, $order->fresh()->platform_profit);
        $this->assertSame(108, $order->fresh()->affiliate_profit);
    }

    /**
     * ADR-105 decision 8 (addendum) — the phantom-profit scenario found
     * while explaining decision 2 to the founder: pre-addendum, a
     * tier-affiliate resend's platformProfit was computed independently
     * of what was actually collected (wholesaleBase - liveCost), so a
     * big enough live-cost jump could make platformProfit +
     * affiliateProfit together exceed the order's own frozen
     * selling_price — crediting both platform and affiliate more than
     * the order ever actually collected, with nothing catching it
     * (wholesaleBase >= liveCost always holds for a non-negative tier%,
     * so the old code's only guard never tripped).
     */
    public function test_platform_profit_never_exceeds_what_the_order_actually_collected_on_a_tier_affiliate_resend(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        // Mirrors a real tier-affiliate order: cost 356, tier 20%,
        // affiliate markup 5% -> wholesaleBase 427, affiliateProfit 21,
        // frozen selling_price (what the affiliate's own customer paid) 448.
        $original = $this->package($game, $supplier, ['cost_price' => 356, 'standard_selling_price' => 900]);
        // Live cost rises to 400 on resend.
        $swap = $this->package($game, $supplier, [
            'name' => 'PUBGG 60 UC', 'supplier_package_ref' => 'PUBGG_60_PG1',
            'cost_price' => 400, 'standard_selling_price' => 900,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'affiliate',
            'wholesale_markup_pct' => 20.00,
            'affiliate_markup_pct' => 5.00,
            'selling_price' => 448,
            'affiliate_profit' => 21,
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'MG-1']))
            ->resend($order, $swap, null, 'Admin');

        // Decision 10: affiliateProfit stays the checkout 21.
        // platformProfit = 448 − 400 − 21 = 27 — NOT 80 (480 − 400), which
        // would have made 400 + 24 + 80 = 504 exceed the 448 collected.
        $order->refresh();
        $this->assertSame(21, $order->affiliate_profit);
        $this->assertSame(27, $order->platform_profit);
        // The conservation identity itself: 400 + 21 + 27 = 448.
        $this->assertSame(448, 400 + $order->platform_profit + $order->affiliate_profit);
    }

    /**
     * ADR-105 decision 8 (addendum): the negative-profit override gate
     * (decision 4) extends to the tier-affiliate/ResellerWallet basis
     * too, now that platformProfit is a residual against the order's
     * own frozen total rather than an always-non-negative
     * wholesaleBase-liveCost figure.
     */
    public function test_tier_affiliate_resend_that_would_result_in_a_platform_loss_requires_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 356, 'standard_selling_price' => 900]);
        // Live cost rises far enough that, after the affiliate's frozen
        // 21, nothing is left for the platform: 448 − 430 − 21 = −3.
        $swap = $this->package($game, $supplier, ['name' => 'PUBGG 60 UC', 'supplier_package_ref' => 'PUBGG_60_PG1', 'cost_price' => 430, 'standard_selling_price' => 900]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'affiliate',
            'wholesale_markup_pct' => 20.00,
            'affiliate_markup_pct' => 5.00,
            'selling_price' => 448,
            'affiliate_profit' => 21,
        ]);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $swap, null, 'Admin');
    }

    /** Same scenario, with an override reason — proceeds, affiliate's cut untouched, platform absorbs the negative. */
    public function test_tier_affiliate_resend_that_would_result_in_a_platform_loss_proceeds_with_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 356, 'standard_selling_price' => 900]);
        $swap = $this->package($game, $supplier, ['name' => 'PUBGG 60 UC', 'supplier_package_ref' => 'PUBGG_60_PG1', 'cost_price' => 430, 'standard_selling_price' => 900]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'affiliate',
            'wholesale_markup_pct' => 20.00,
            'affiliate_markup_pct' => 5.00,
            'selling_price' => 448,
            'affiliate_profit' => 21,
        ]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'MG-1']))
            ->resend($order, $swap, null, 'Admin', overrideReason: 'Founder-approved: deliver anyway, absorb the loss');

        $this->assertSame(21, $result->affiliate_profit);
        $this->assertSame(-3, $result->platform_profit);
    }

    public function test_does_not_credit_the_ledger_when_the_resend_fails(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->service($this->fakeSupplierAdapter(false))->resend($order, $package, null, 'Admin');

        $this->assertSame(0, LedgerEntry::query()->count());
    }

    /**
     * ADR-111 decision 5: resend() needs no code change of its own —
     * it sets affiliate_profit correctly (per its own per-basis formula)
     * BEFORE calling fulfill(), and fulfill()'s own Success-branch
     * correction (decision 3) then unconditionally re-derives
     * platform_profit from the now-known REAL cost, using the
     * affiliate_profit resend() already set. The real cost here (800
     * sen) differs from both the swap package's live catalog cost_price
     * (950) and resend()'s own pre-fulfill estimate (50) — the final
     * stored figure must reflect the real cost, not either estimate.
     */
    public function test_fulfill_overwrites_resends_own_estimate_with_the_real_cost(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 950, 'standard_selling_price' => 1045]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000, 'affiliate_markup_pct' => 0]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1', 'price' => 8.0]))
            ->resend($order, $swap, null, 'Admin');

        // 1000 (frozen selling_price) - 800 (real cost, not the swap
        // package's live 950 catalog cost) - 0 (affiliate_profit,
        // resend()'s own, untouched) = 200 — not resend()'s own
        // pre-fulfill estimate of 50 (1000 - 950 - 0).
        $fresh = $order->fresh();
        $this->assertSame(800, $fresh->real_cost_price_sen);
        $this->assertSame(0, $fresh->affiliate_profit);
        $this->assertSame(200, $fresh->platform_profit);
        $this->assertSame(200, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
    }
}
