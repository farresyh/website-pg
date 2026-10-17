<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CheckoutControllerFixtures;
use Tests\TestCase;

/** This file: preview-totals matches the real charge. */
class CheckoutControllerPreviewTotalsTest extends TestCase
{
    use CheckoutControllerFixtures;
    use RefreshDatabase;

    /**
     * Bug fix, 2026-08-30 — CheckoutController::previewTotal(). The
     * storefront's pre-payment total never included the transaction
     * fee before this; these tests lock the new preview endpoint's
     * numbers directly against the real /api/checkout charge for the
     * same inputs, so the two can never quietly drift apart again.
     */
    public function test_preview_totals_matches_a_standard_order_with_no_voucher_or_member(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500, flat_fee_sen = 210

        $preview = $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'channel_code' => 'FPX_ABMB',
        ]);

        $preview->assertOk();
        $preview->assertExactJson([
            'selling_price_sen' => 500,
            'member_discount_percent' => null,
            'voucher_discount_sen' => 0,
            'transaction_fee_sen' => 210,
            'final_amount_sen' => 710,
        ]);

        // Parity check against the real charge for the identical inputs.
        $this->postJson('/api/checkout', $this->payload($game, $package))->assertCreated();
        $order = Order::query()->firstOrFail();
        $this->assertSame(710, $order->final_amount);
    }

    public function test_preview_totals_reflects_a_partial_cover_voucher_same_as_the_real_charge(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = $this->voucher(['remaining' => 200]);

        $preview = $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'channel_code' => 'FPX_ABMB',
            'voucher_code' => $voucher->code,
            'customer_email' => 'buyer@example.com',
        ]);

        $preview->assertOk();
        // feeBase = 500 - 200 = 300; flat_fee_sen = 210 -> matches
        // test_partial_cover_voucher_reduces_fee_base_and_redeems_after_gateway_success()'s real-order numbers exactly.
        $preview->assertExactJson([
            'selling_price_sen' => 500,
            'member_discount_percent' => null,
            'voucher_discount_sen' => 200,
            'transaction_fee_sen' => 210,
            'final_amount_sen' => 510,
        ]);
    }

    public function test_preview_totals_reflects_a_full_cover_voucher_skipping_the_flat_fee(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(); // selling_price = 500
        $voucher = $this->voucher(['remaining' => 1000]);

        $preview = $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'channel_code' => 'FPX_ABMB',
            'voucher_code' => $voucher->code,
            'customer_email' => 'buyer@example.com',
        ]);

        $preview->assertOk();
        // ADR-024 decision #5: feeBase 0 -> transaction_fee/final_amount forced to 0, not the flat_fee_sen=210.
        $preview->assertExactJson([
            'selling_price_sen' => 500,
            'member_discount_percent' => null,
            'voucher_discount_sen' => 500,
            'transaction_fee_sen' => 0,
            'final_amount_sen' => 0,
        ]);
    }

    public function test_preview_totals_uses_the_authenticated_members_own_price(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage(); // cost 1000, standard 1200, Tier 2 -> member price 1040
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 5000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('member@example.com');

        $preview = $this->postJson(
            '/api/checkout/preview-totals',
            ['game_id' => $game->id, 'package_id' => $package->id, 'channel_code' => 'FPX_ABMB'],
            ['Authorization' => "Bearer {$token}"],
        );

        $preview->assertOk();
        $preview->assertExactJson([
            'selling_price_sen' => 1040,
            'member_discount_percent' => 80.0,
            'voucher_discount_sen' => 0,
            'transaction_fee_sen' => 210,
            'final_amount_sen' => 1250,
        ]);
    }

    public function test_preview_totals_rejects_an_inactive_package(): void
    {
        $this->activeChannel();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage([], ['is_active' => false]);

        $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id,
            'package_id' => $package->id,
            'channel_code' => 'FPX_ABMB',
        ])->assertStatus(422);
    }
}
