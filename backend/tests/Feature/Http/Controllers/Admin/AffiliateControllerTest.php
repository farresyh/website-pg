<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateUser;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Withdrawal;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Affiliate\AffiliateSubscriptionService;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use App\Services\Affiliate\Domain\AffiliateDomainProvider;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeAffiliateDomainProvider;
use Tests\TestCase;

/**
 * ADR-058 58b (RES-1..3, RES-5, RES-6): admin Affiliate Management.
 */
class AffiliateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function actAsSuperAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function affiliate(array $overrides = []): Affiliate
    {
        return Affiliate::query()->create(array_merge([
            'business_name' => 'Acme Resell',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ], $overrides));
    }

    private function tier(array $overrides = []): AffiliateMembershipTier
    {
        return AffiliateMembershipTier::query()->create(array_merge([
            'name' => 'Silver',
            'monthly_fee_sen' => 5000,
            'markup_percent' => 5,
            'is_active' => true,
            'sort_order' => 1,
        ], $overrides));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/affiliates')->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/affiliates')->assertUnauthorized();
    }

    public function test_index_lists_affiliates_with_balance_and_subscription(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate();
        $tier = $this->tier();
        app(AffiliateSubscriptionService::class)->assignTier($r, $tier);

        app(LedgerService::class)->openAccount('affiliate', $r->id);
        app(LedgerService::class)->credit('affiliate', $r->id, 12345, 'order_profit', 'order', 1);

        $response = $this->getJson('/api/affiliates');

        $response->assertOk();
        $response->assertJsonPath('affiliates.0.earnings_balance_sen', 12345);
        $response->assertJsonPath('affiliates.0.subscription.tier_name', 'Silver');
        $response->assertJsonPath('affiliates.0.subscription.status', 'active');
    }

    public function test_index_and_show_carry_the_active_membership_count(): void
    {
        // ADR-080 decision 4: the affiliate form warns before Membership
        // is turned off on a brand that still has active members.
        $this->actAsSuperAdmin();
        $brand = $this->affiliate(['business_name' => 'Zeta Owned', 'is_owned' => true, 'membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();

        foreach (['active', 'active', 'expired'] as $i => $status) {
            Membership::query()->create([
                'affiliate_id' => $brand->id,
                'email' => "m{$i}@example.com",
                'membership_plan_id' => $plan->id,
                'status' => $status,
                'cycle_started_at' => now(),
                'quota_remaining_sen' => 1000,
                'expires_at' => now()->addDays(10),
            ]);
        }

        $row = collect($this->getJson('/api/affiliates')->assertOk()->json('affiliates'))
            ->firstWhere('id', $brand->id);
        $this->assertSame(2, $row['active_membership_count']);

        $this->getJson("/api/affiliates/{$brand->id}")
            ->assertOk()
            ->assertJsonPath('affiliate.active_membership_count', 2);
    }

    public function test_store_creates_affiliate_first_user_and_sends_invite(): void
    {
        Http::fake();
        $this->actAsSuperAdmin();
        $tier = $this->tier();

        $response = $this->postJson('/api/affiliates', [
            'business_name' => 'New Shop',
            'contact_name' => 'Jane',
            'email' => 'shop@example.com',
            'markup_pct' => 8,
            'max_markup_pct' => 20,
            'tier_id' => $tier->id,
            'user_name' => 'Jane Doe',
            'user_email' => 'jane@example.com',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('affiliates', ['business_name' => 'New Shop']);
        $this->assertDatabaseHas('affiliate_users', ['email' => 'jane@example.com', 'password' => null]);
        $this->assertDatabaseHas('affiliate_subscriptions', ['status' => 'active']);
        $this->assertDatabaseHas('affiliate_tier_changes', ['to_tier_id' => $tier->id]);
        Http::assertSentCount(1);
    }

    public function test_store_rejects_max_markup_below_markup(): void
    {
        $this->actAsSuperAdmin();

        $this->postJson('/api/affiliates', [
            'business_name' => 'Bad Shop',
            'markup_pct' => 20,
            'max_markup_pct' => 10,
            'user_name' => 'X',
            'user_email' => 'x@example.com',
        ])->assertUnprocessable();
    }

    public function test_store_rolls_back_when_invite_send_fails(): void
    {
        $this->actAsSuperAdmin();
        Http::fake(fn () => Http::response('boom', 500));

        $this->postJson('/api/affiliates', [
            'business_name' => 'Doomed Shop',
            'markup_pct' => 5,
            'user_name' => 'Y',
            'user_email' => 'y@example.com',
        ])->assertServerError();

        $this->assertDatabaseMissing('affiliates', ['business_name' => 'Doomed Shop']);
        $this->assertDatabaseMissing('affiliate_users', ['email' => 'y@example.com']);
    }

    public function test_assign_tier_writes_audit_row(): void
    {
        $admin = $this->actAsSuperAdmin();
        $r = $this->affiliate();
        $silver = $this->tier(['name' => 'Silver']);
        $gold = $this->tier(['name' => 'Gold', 'sort_order' => 2]);

        $this->postJson("/api/affiliates/{$r->id}/tier", ['tier_id' => $silver->id])->assertOk();
        $this->postJson("/api/affiliates/{$r->id}/tier", ['tier_id' => $gold->id, 'note' => 'upgrade'])->assertOk();

        $this->assertDatabaseHas('affiliate_tier_changes', [
            'affiliate_id' => $r->id,
            'from_tier_id' => $silver->id,
            'to_tier_id' => $gold->id,
            'admin_user_id' => $admin->id,
            'note' => 'upgrade',
        ]);
        $this->assertSame($gold->id, $r->fresh()->subscription->affiliate_membership_tier_id);
    }

    public function test_charge_tier_fee_debits_earnings(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate();
        $tier = $this->tier(['monthly_fee_sen' => 5000]);
        app(AffiliateSubscriptionService::class)->assignTier($r, $tier);

        app(LedgerService::class)->openAccount('affiliate', $r->id);
        app(LedgerService::class)->credit('affiliate', $r->id, 8000, 'order_profit', 'order', 1);

        $this->postJson("/api/affiliates/{$r->id}/tier/charge")->assertOk();

        $this->assertSame(3000, app(LedgerService::class)->balance('affiliate', $r->id));
        $this->assertDatabaseHas('ledger_entries', ['type' => 'affiliate_tier_fee', 'amount' => -5000]);
    }

    public function test_charge_tier_fee_starts_grace_when_earnings_short(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate();
        $tier = $this->tier(['monthly_fee_sen' => 5000]);
        app(AffiliateSubscriptionService::class)->assignTier($r, $tier);

        $this->postJson("/api/affiliates/{$r->id}/tier/charge")->assertOk();

        $this->assertSame(AffiliateSubscriptionStatus::Grace, $r->fresh()->subscription->status);
    }

    public function test_reactivate_subscription_from_lapsed(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate();
        $tier = $this->tier();
        app(AffiliateSubscriptionService::class)->assignTier($r, $tier);
        $r->subscription->update(['status' => AffiliateSubscriptionStatus::Lapsed]);

        $this->postJson("/api/affiliates/{$r->id}/tier/reactivate")->assertOk();

        $this->assertSame(AffiliateSubscriptionStatus::Active, $r->fresh()->subscription->status);
    }

    public function test_update_status_deactivates_and_reactivates(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate();

        $this->patchJson("/api/affiliates/{$r->id}/status", ['status' => 'inactive'])->assertOk();
        $this->assertSame('inactive', $r->fresh()->status);

        $this->patchJson("/api/affiliates/{$r->id}/status", ['status' => 'active'])->assertOk();
        $this->assertSame('active', $r->fresh()->status);
    }

    public function test_destroy_soft_deletes_when_nothing_owed(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate();

        $this->deleteJson("/api/affiliates/{$r->id}")->assertOk();

        $this->assertSoftDeleted('affiliates', ['id' => $r->id]);
    }

    public function test_destroy_blocked_when_earnings_nonzero(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate();
        app(LedgerService::class)->openAccount('affiliate', $r->id);
        app(LedgerService::class)->credit('affiliate', $r->id, 500, 'order_profit', 'order', 1);

        $this->deleteJson("/api/affiliates/{$r->id}")->assertUnprocessable();
        $this->assertDatabaseHas('affiliates', ['id' => $r->id, 'deleted_at' => null]);
    }

    public function test_destroy_blocked_when_pending_withdrawal(): void
    {
        $admin = $this->actAsSuperAdmin();
        $r = $this->affiliate();
        Withdrawal::query()->create([
            'owner_type' => 'affiliate',
            'owner_id' => $r->id,
            'amount' => 1000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'Acme',
            'status' => 'pending',
            'requested_by' => $admin->id,
        ]);

        $this->deleteJson("/api/affiliates/{$r->id}")->assertUnprocessable();
    }

    public function test_primary_affiliate_cannot_be_deleted(): void
    {
        $this->actAsSuperAdmin();
        $owner = $this->primaryAffiliate();

        $this->deleteJson("/api/affiliates/{$owner->id}")->assertUnprocessable();
        $this->assertDatabaseHas('affiliates', ['id' => $owner->id, 'deleted_at' => null]);
    }

    public function test_a_non_primary_owned_brand_follows_the_normal_delete_rules(): void
    {
        $this->primaryAffiliate();
        $this->actAsSuperAdmin();
        $brand = $this->affiliate(['is_owned' => true, 'business_name' => 'Our second brand']);

        // is_owned but not is_primary — nothing owed, so it deletes.
        $this->deleteJson("/api/affiliates/{$brand->id}")->assertOk();
        $this->assertSoftDeleted('affiliates', ['id' => $brand->id]);
    }

    public function test_store_marks_an_owned_brand_and_enables_membership(): void
    {
        Http::fake();
        $this->actAsSuperAdmin();

        $this->postJson('/api/affiliates', [
            'business_name' => 'Our New Brand',
            'markup_pct' => 0,
            'user_name' => 'Owner',
            'user_email' => 'owner@example.com',
            'is_owned' => true,
            'membership_enabled' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('affiliates', [
            'business_name' => 'Our New Brand',
            'is_owned' => true,
            'membership_enabled' => true,
        ]);
        // Never assigned is_primary — that stays on the one backfilled row.
        $this->assertDatabaseMissing('affiliates', ['business_name' => 'Our New Brand', 'is_primary' => true]);
    }

    public function test_store_ignores_the_membership_toggle_for_a_third_party_affiliate(): void
    {
        Http::fake();
        $this->actAsSuperAdmin();

        $this->postJson('/api/affiliates', [
            'business_name' => 'Third Party',
            'markup_pct' => 12,
            'user_name' => 'Rep',
            'user_email' => 'rep@example.com',
            'is_owned' => false,
            'membership_enabled' => true,
        ])->assertCreated();

        // Consumer Membership is an internal-brand-only capability (ADR-061
        // decision 8) — the toggle is forced off regardless of the input.
        $this->assertDatabaseHas('affiliates', [
            'business_name' => 'Third Party',
            'is_owned' => false,
            'membership_enabled' => false,
        ]);
    }

    public function test_update_toggles_the_primary_brand_membership_and_flushes_the_catalog_cache(): void
    {
        $this->actAsSuperAdmin();
        $owner = $this->primaryAffiliate(['membership_enabled' => false]);

        Cache::store(config('cache.catalog_packages_store'))
            ->tags(['catalog.packages'])
            ->put('sentinel', 'x', 300);

        $this->putJson("/api/affiliates/{$owner->id}", [
            'business_name' => $owner->business_name,
            'markup_pct' => 0,
            'is_owned' => true,
            'membership_enabled' => true,
        ])->assertOk();

        $this->assertDatabaseHas('affiliates', ['id' => $owner->id, 'membership_enabled' => true]);
        $this->assertNull(
            Cache::store(config('cache.catalog_packages_store'))
                ->tags(['catalog.packages'])
                ->get('sentinel'),
        );
    }

    public function test_add_user_and_resend_invite(): void
    {
        Http::fake();
        $this->actAsSuperAdmin();
        $r = $this->affiliate();

        $this->postJson("/api/affiliates/{$r->id}/users", [
            'name' => 'Second Staff',
            'email' => 'second@acme.test',
        ])->assertOk();

        $user = AffiliateUser::query()->where('email', 'second@acme.test')->firstOrFail();
        $this->assertNull($user->password);

        $this->postJson("/api/affiliates/{$r->id}/users/{$user->id}/resend-invite")->assertOk();
        Http::assertSentCount(2);
    }

    // --- ADR-060 PR-5: custom-domain break-glass + RES-5/RES-6 hooks ---

    private function fakeDomains(): FakeAffiliateDomainProvider
    {
        $fake = new FakeAffiliateDomainProvider;
        $this->app->instance(AffiliateDomainProvider::class, $fake);

        return $fake;
    }

    public function test_show_lists_custom_domains(): void
    {
        $this->fakeDomains();
        $this->actAsSuperAdmin();
        $affiliate = $this->affiliate();
        $affiliate->customDomains()->create([
            'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Active,
            'provider' => 'vercel',
            'provider_ref' => 'shop.acme.com',
            'is_primary' => true,
        ]);

        $this->getJson("/api/affiliates/{$affiliate->id}")
            ->assertOk()
            ->assertJsonPath('domains.0.hostname', 'shop.acme.com')
            ->assertJsonPath('domains.0.provider_managed', true);
    }

    public function test_deactivating_an_affiliate_suspends_its_domains(): void
    {
        $this->fakeDomains();
        $this->actAsSuperAdmin();
        $affiliate = $this->affiliate();
        $domain = $affiliate->customDomains()->create([
            'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Active,
            'provider' => 'vercel',
            'provider_ref' => 'shop.acme.com',
            'is_primary' => true,
        ]);

        $this->patchJson("/api/affiliates/{$affiliate->id}/status", ['status' => 'inactive'])->assertOk();

        $this->assertSame(
            AffiliateDomainStatus::Suspended,
            $domain->fresh()->status,
        );
    }

    public function test_deleting_an_affiliate_tears_down_its_domains(): void
    {
        $fake = $this->fakeDomains();
        $this->actAsSuperAdmin();
        $affiliate = $this->affiliate();
        $affiliate->customDomains()->create([
            'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Active,
            'provider' => 'vercel',
            'provider_ref' => 'shop.acme.com',
        ]);

        $this->deleteJson("/api/affiliates/{$affiliate->id}")->assertOk();

        $this->assertSame(1, $fake->opCount('detach'));
        $this->assertSame(0, $affiliate->customDomains()->count());
    }

    public function test_admin_can_force_recheck_and_remove_a_domain(): void
    {
        $fake = $this->fakeDomains();
        $this->actAsSuperAdmin();
        $affiliate = $this->affiliate();
        $domain = $affiliate->customDomains()->create([
            'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Pending,
            'provider' => 'vercel',
            'provider_ref' => 'shop.acme.com',
        ]);
        $fake->markVerified('shop.acme.com');

        $this->postJson("/api/affiliates/{$affiliate->id}/domains/{$domain->id}/recheck")
            ->assertOk()
            ->assertJsonPath('domains.0.status', 'active');

        $this->deleteJson("/api/affiliates/{$affiliate->id}/domains/{$domain->id}")->assertOk();
        $this->assertDatabaseMissing('affiliate_domains', ['id' => $domain->id]);
    }
}
