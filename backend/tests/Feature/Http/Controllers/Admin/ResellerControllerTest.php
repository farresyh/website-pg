<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\ResellerMembershipTier;
use App\Models\ResellerUser;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerSubscriptionService;
use App\Services\Reseller\ResellerSubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-058 58b (RES-1..3, RES-5, RES-6): admin Reseller Management.
 */
class ResellerControllerTest extends TestCase
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

    private function reseller(array $overrides = []): Reseller
    {
        return Reseller::query()->create(array_merge([
            'business_name' => 'Acme Resell',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ], $overrides));
    }

    private function tier(array $overrides = []): ResellerMembershipTier
    {
        return ResellerMembershipTier::query()->create(array_merge([
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

        $this->getJson('/api/resellers')->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/resellers')->assertUnauthorized();
    }

    public function test_index_lists_resellers_with_balance_and_subscription(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller();
        $tier = $this->tier();
        app(ResellerSubscriptionService::class)->assignTier($r, $tier);

        app(LedgerService::class)->openAccount('reseller', $r->id);
        app(LedgerService::class)->credit('reseller', $r->id, 12345, 'order_profit', 'order', 1);

        $response = $this->getJson('/api/resellers');

        $response->assertOk();
        $response->assertJsonPath('resellers.0.earnings_balance_sen', 12345);
        $response->assertJsonPath('resellers.0.subscription.tier_name', 'Silver');
        $response->assertJsonPath('resellers.0.subscription.status', 'active');
    }

    public function test_store_creates_reseller_first_user_and_sends_invite(): void
    {
        Http::fake();
        $this->actAsSuperAdmin();
        $tier = $this->tier();

        $response = $this->postJson('/api/resellers', [
            'business_name' => 'New Shop',
            'contact_name' => 'Jane',
            'email' => 'shop@example.com',
            'markup_pct' => 8,
            'max_markup_pct' => 20,
            'domains' => ['shop.example.com'],
            'tier_id' => $tier->id,
            'user_name' => 'Jane Doe',
            'user_email' => 'jane@example.com',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('resellers', ['business_name' => 'New Shop']);
        $this->assertDatabaseHas('reseller_users', ['email' => 'jane@example.com', 'password' => null]);
        $this->assertDatabaseHas('reseller_subscriptions', ['status' => 'active']);
        $this->assertDatabaseHas('reseller_tier_changes', ['to_tier_id' => $tier->id]);
        Http::assertSentCount(1);
    }

    public function test_store_rejects_max_markup_below_markup(): void
    {
        $this->actAsSuperAdmin();

        $this->postJson('/api/resellers', [
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

        $this->postJson('/api/resellers', [
            'business_name' => 'Doomed Shop',
            'markup_pct' => 5,
            'user_name' => 'Y',
            'user_email' => 'y@example.com',
        ])->assertServerError();

        $this->assertDatabaseMissing('resellers', ['business_name' => 'Doomed Shop']);
        $this->assertDatabaseMissing('reseller_users', ['email' => 'y@example.com']);
    }

    public function test_assign_tier_writes_audit_row(): void
    {
        $admin = $this->actAsSuperAdmin();
        $r = $this->reseller();
        $silver = $this->tier(['name' => 'Silver']);
        $gold = $this->tier(['name' => 'Gold', 'sort_order' => 2]);

        $this->postJson("/api/resellers/{$r->id}/tier", ['tier_id' => $silver->id])->assertOk();
        $this->postJson("/api/resellers/{$r->id}/tier", ['tier_id' => $gold->id, 'note' => 'upgrade'])->assertOk();

        $this->assertDatabaseHas('reseller_tier_changes', [
            'reseller_id' => $r->id,
            'from_tier_id' => $silver->id,
            'to_tier_id' => $gold->id,
            'admin_user_id' => $admin->id,
            'note' => 'upgrade',
        ]);
        $this->assertSame($gold->id, $r->fresh()->subscription->reseller_membership_tier_id);
    }

    public function test_charge_tier_fee_debits_earnings(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller();
        $tier = $this->tier(['monthly_fee_sen' => 5000]);
        app(ResellerSubscriptionService::class)->assignTier($r, $tier);

        app(LedgerService::class)->openAccount('reseller', $r->id);
        app(LedgerService::class)->credit('reseller', $r->id, 8000, 'order_profit', 'order', 1);

        $this->postJson("/api/resellers/{$r->id}/tier/charge")->assertOk();

        $this->assertSame(3000, app(LedgerService::class)->balance('reseller', $r->id));
        $this->assertDatabaseHas('ledger_entries', ['type' => 'reseller_tier_fee', 'amount' => -5000]);
    }

    public function test_charge_tier_fee_starts_grace_when_earnings_short(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller();
        $tier = $this->tier(['monthly_fee_sen' => 5000]);
        app(ResellerSubscriptionService::class)->assignTier($r, $tier);

        $this->postJson("/api/resellers/{$r->id}/tier/charge")->assertOk();

        $this->assertSame(ResellerSubscriptionStatus::Grace, $r->fresh()->subscription->status);
    }

    public function test_reactivate_subscription_from_lapsed(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller();
        $tier = $this->tier();
        app(ResellerSubscriptionService::class)->assignTier($r, $tier);
        $r->subscription->update(['status' => ResellerSubscriptionStatus::Lapsed]);

        $this->postJson("/api/resellers/{$r->id}/tier/reactivate")->assertOk();

        $this->assertSame(ResellerSubscriptionStatus::Active, $r->fresh()->subscription->status);
    }

    public function test_update_status_deactivates_and_reactivates(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller();

        $this->patchJson("/api/resellers/{$r->id}/status", ['status' => 'inactive'])->assertOk();
        $this->assertSame('inactive', $r->fresh()->status);

        $this->patchJson("/api/resellers/{$r->id}/status", ['status' => 'active'])->assertOk();
        $this->assertSame('active', $r->fresh()->status);
    }

    public function test_destroy_soft_deletes_when_nothing_owed(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller();

        $this->deleteJson("/api/resellers/{$r->id}")->assertOk();

        $this->assertSoftDeleted('resellers', ['id' => $r->id]);
    }

    public function test_destroy_blocked_when_earnings_nonzero(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller();
        app(LedgerService::class)->openAccount('reseller', $r->id);
        app(LedgerService::class)->credit('reseller', $r->id, 500, 'order_profit', 'order', 1);

        $this->deleteJson("/api/resellers/{$r->id}")->assertUnprocessable();
        $this->assertDatabaseHas('resellers', ['id' => $r->id, 'deleted_at' => null]);
    }

    public function test_destroy_blocked_when_pending_withdrawal(): void
    {
        $admin = $this->actAsSuperAdmin();
        $r = $this->reseller();
        Withdrawal::query()->create([
            'owner_type' => 'reseller',
            'owner_id' => $r->id,
            'amount' => 1000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'Acme',
            'status' => 'pending',
            'requested_by' => $admin->id,
        ]);

        $this->deleteJson("/api/resellers/{$r->id}")->assertUnprocessable();
    }

    public function test_platform_owner_cannot_be_deleted(): void
    {
        $this->actAsSuperAdmin();
        $owner = Reseller::platformOwner();

        $this->deleteJson("/api/resellers/{$owner->id}")->assertUnprocessable();
    }

    public function test_add_user_and_resend_invite(): void
    {
        Http::fake();
        $this->actAsSuperAdmin();
        $r = $this->reseller();

        $this->postJson("/api/resellers/{$r->id}/users", [
            'name' => 'Second Staff',
            'email' => 'second@acme.test',
        ])->assertOk();

        $user = ResellerUser::query()->where('email', 'second@acme.test')->firstOrFail();
        $this->assertNull($user->password);

        $this->postJson("/api/resellers/{$r->id}/users/{$user->id}/resend-invite")->assertOk();
        Http::assertSentCount(2);
    }
}
