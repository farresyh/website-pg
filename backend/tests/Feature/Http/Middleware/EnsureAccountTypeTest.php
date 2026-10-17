<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Affiliate;
use App\Models\AffiliateUser;
use App\Models\Reseller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-072 decision 4 / PR-G: the mandatory backend authorization gate —
 * a `Reseller` (wallet) account must never reach an Affiliate-only
 * endpoint (Withdrawal, Subscription, ...) or vice versa, regardless of
 * what the portal frontend chooses to render. Since the ADR-072
 * 2026-10-18 addendum, `RouteAccessTest` checks that every portal route
 * carries its guard (new routes included); this file proves the guard
 * really refuses the other account type, once in each direction, plus
 * the cases with their own logic.
 */
class EnsureAccountTypeTest extends TestCase
{
    use RefreshDatabase;

    private function affiliateToken(): string
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme Resell', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active',
        ]);
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $affiliate->id,
            'name' => 'Staff', 'email' => 'staff@acme.test',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    private function resellerToken(): string
    {
        $reseller = Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true]);
        $user = AffiliateUser::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $reseller->id,
            'name' => 'Reseller Staff', 'email' => 'staff@wallet-reseller.test',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    // --- A Reseller (wallet) token on every existing Affiliate-only endpoint. ---

    public function test_a_reseller_token_cannot_reach_the_affiliate_dashboard(): void
    {
        $this->withToken($this->resellerToken())->getJson('/api/affiliate/dashboard')->assertForbidden();
    }

    // --- An Affiliate token on every new Reseller-portal endpoint. ---

    public function test_an_affiliate_token_cannot_read_the_reseller_wallet(): void
    {
        $this->withToken($this->affiliateToken())->getJson('/api/reseller-portal/wallet')->assertForbidden();
    }

    // --- The gate holds for the shared endpoints too (both account types allowed). ---

    public function test_me_works_for_both_account_types(): void
    {
        $this->withToken($this->affiliateToken())->getJson('/api/affiliate/me')->assertOk();
        $this->withToken($this->resellerToken())->getJson('/api/affiliate/me')->assertOk();
    }

    // --- Item 31 (2026-09-26 audit): a deactivated Reseller must be
    // blocked from the portal too, matching the REST API/Bot's existing
    // full block on the same `resellers.is_active` column. ---
}
