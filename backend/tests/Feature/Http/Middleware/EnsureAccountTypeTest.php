<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Affiliate;
use App\Models\AffiliateUser;
use App\Models\Reseller;
use App\Models\ResellerApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-072 decision 4 / PR-G: the mandatory backend authorization gate —
 * a `Reseller` (wallet) account must never reach an Affiliate-only
 * endpoint (Withdrawal, Subscription, ...) or vice versa, regardless of
 * what the portal frontend chooses to render. One test per gated
 * endpoint, per ADR-072's own consequence-to-track requirement. No
 * data providers — this codebase's own test suite never uses them,
 * every explicit method mirrors that existing style.
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

    public function test_a_reseller_token_cannot_list_affiliate_orders(): void
    {
        $this->withToken($this->resellerToken())->getJson('/api/affiliate/orders')->assertForbidden();
    }

    public function test_a_reseller_token_cannot_show_an_affiliate_order(): void
    {
        $this->withToken($this->resellerToken())->getJson('/api/affiliate/orders/PG-DOES-NOT-EXIST')->assertForbidden();
    }

    public function test_a_reseller_token_cannot_read_affiliate_earnings(): void
    {
        $this->withToken($this->resellerToken())->getJson('/api/affiliate/earnings')->assertForbidden();
    }

    public function test_a_reseller_token_cannot_read_the_affiliate_subscription(): void
    {
        $this->withToken($this->resellerToken())->getJson('/api/affiliate/subscription')->assertForbidden();
    }

    public function test_a_reseller_token_cannot_read_the_affiliate_profile(): void
    {
        $this->withToken($this->resellerToken())->getJson('/api/affiliate/profile')->assertForbidden();
    }

    public function test_a_reseller_token_cannot_update_the_affiliate_profile(): void
    {
        $this->withToken($this->resellerToken())->putJson('/api/affiliate/profile', ['contact_name' => 'x'])->assertForbidden();
    }

    public function test_a_reseller_token_cannot_list_affiliate_withdrawals(): void
    {
        $this->withToken($this->resellerToken())->getJson('/api/affiliate/withdrawals')->assertForbidden();
    }

    public function test_a_reseller_token_cannot_request_an_affiliate_withdrawal(): void
    {
        $this->withToken($this->resellerToken())->postJson('/api/affiliate/withdrawals', ['amount' => 1000])->assertForbidden();
    }

    public function test_a_reseller_token_cannot_end_an_affiliate_impersonation_session(): void
    {
        $this->withToken($this->resellerToken())->postJson('/api/affiliate/impersonation/end')->assertForbidden();
    }

    // --- An Affiliate token on every new Reseller-portal endpoint. ---

    public function test_an_affiliate_token_cannot_read_the_reseller_wallet(): void
    {
        $this->withToken($this->affiliateToken())->getJson('/api/reseller-portal/wallet')->assertForbidden();
    }

    public function test_an_affiliate_token_cannot_top_up_a_reseller_wallet(): void
    {
        $this->withToken($this->affiliateToken())->postJson('/api/reseller-portal/wallet/topup', [
            'amount_sen' => 1000, 'channel_code' => 'fpx',
        ])->assertForbidden();
    }

    public function test_an_affiliate_token_cannot_list_reseller_orders(): void
    {
        $this->withToken($this->affiliateToken())->getJson('/api/reseller-portal/orders')->assertForbidden();
    }

    public function test_an_affiliate_token_cannot_show_a_reseller_order(): void
    {
        $this->withToken($this->affiliateToken())->getJson('/api/reseller-portal/orders/PG-DOES-NOT-EXIST')->assertForbidden();
    }

    public function test_an_affiliate_token_cannot_read_the_reseller_profile(): void
    {
        $this->withToken($this->affiliateToken())->getJson('/api/reseller-portal/profile')->assertForbidden();
    }

    public function test_an_affiliate_token_cannot_list_reseller_api_keys(): void
    {
        $this->withToken($this->affiliateToken())->getJson('/api/reseller-portal/api-keys')->assertForbidden();
    }

    public function test_an_affiliate_token_cannot_issue_a_reseller_api_key(): void
    {
        $this->withToken($this->affiliateToken())->postJson('/api/reseller-portal/api-keys', ['name' => 'x'])->assertForbidden();
    }

    public function test_an_affiliate_token_cannot_revoke_a_reseller_api_key(): void
    {
        // A real row so route-model-binding resolves before the gate
        // rejects it — proves the middleware itself blocks this, not an
        // incidental 404 from a nonexistent id.
        $reseller = Reseller::query()->create(['business_name' => 'Some Reseller', 'is_active' => true]);
        $key = ResellerApiKey::query()->create(['reseller_id' => $reseller->id, 'name' => 'x', 'key_hash' => 'hash']);

        $this->withToken($this->affiliateToken())->deleteJson("/api/reseller-portal/api-keys/{$key->id}")->assertForbidden();
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

    public function test_a_deactivated_reseller_cannot_reach_the_portal_even_with_an_active_login_row(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'Suspended Reseller', 'is_active' => false]);
        $user = AffiliateUser::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $reseller->id,
            'name' => 'Reseller Staff', 'email' => 'staff@suspended-reseller.test',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);
        $token = $user->createToken('affiliate')->plainTextToken;

        $this->withToken($token)->getJson('/api/reseller-portal/wallet')->assertForbidden();
    }
}
