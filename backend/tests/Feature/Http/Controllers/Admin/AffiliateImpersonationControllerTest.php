<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateImpersonationSession;
use App\Models\AffiliateUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-058 58b (RES-4): admin impersonation of an affiliate portal session.
 */
class AffiliateImpersonationControllerTest extends TestCase
{
    use RefreshDatabase;

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
            'status' => 'active',
        ], $overrides));
    }

    public function test_start_mints_scoped_expiring_token_and_audits(): void
    {
        $admin = $this->actAsSuperAdmin();
        $r = $this->affiliate();
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate',
            'owner_id' => $r->id,
            'name' => 'Staff',
            'email' => 'staff@acme.test',
            'password' => Hash::make('x'),
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/affiliates/{$r->id}/impersonate", ['reason' => 'debugging']);

        $response->assertCreated();
        $response->assertJsonStructure(['session_id', 'token', 'acting_as' => ['id', 'email'], 'portal_url', 'expires_at']);

        $this->assertDatabaseHas('affiliate_impersonation_sessions', [
            'affiliate_id' => $r->id,
            'admin_user_id' => $admin->id,
            'affiliate_user_id' => $user->id,
            'ended_at' => null,
        ]);

        $tokenId = AffiliateImpersonationSession::query()->first()->personal_access_token_id;
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId, 'abilities' => '["impersonate"]']);
        $this->assertNotNull(PersonalAccessToken::query()->find($tokenId)->expires_at);
    }

    public function test_start_rejected_when_affiliate_has_no_active_user(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate();

        $this->postJson("/api/affiliates/{$r->id}/impersonate")->assertUnprocessable();
    }

    public function test_start_rejected_when_affiliate_inactive(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate(['status' => 'inactive']);
        AffiliateUser::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $r->id, 'name' => 'S', 'email' => 's@acme.test',
            'password' => Hash::make('x'), 'is_active' => true,
        ]);

        $this->postJson("/api/affiliates/{$r->id}/impersonate")->assertUnprocessable();
    }

    public function test_end_revokes_token_and_closes_session(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate();
        AffiliateUser::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $r->id, 'name' => 'S', 'email' => 's@acme.test',
            'password' => Hash::make('x'), 'is_active' => true,
        ]);

        $sessionId = $this->postJson("/api/affiliates/{$r->id}/impersonate")->json('session_id');
        $tokenId = AffiliateImpersonationSession::query()->find($sessionId)->personal_access_token_id;

        $this->postJson("/api/affiliate-impersonation-sessions/{$sessionId}/end")->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertNotNull(AffiliateImpersonationSession::query()->find($sessionId)->ended_at);
    }

    public function test_deactivating_affiliate_ends_live_impersonation(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate();
        AffiliateUser::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $r->id, 'name' => 'S', 'email' => 's@acme.test',
            'password' => Hash::make('x'), 'is_active' => true,
        ]);
        $sessionId = $this->postJson("/api/affiliates/{$r->id}/impersonate")->json('session_id');

        $this->patchJson("/api/affiliates/{$r->id}/status", ['status' => 'inactive'])->assertOk();

        $session = AffiliateImpersonationSession::query()->find($sessionId);
        $this->assertSame('affiliate_deactivated', $session->ended_reason);
        $this->assertNotNull($session->ended_at);
    }

    public function test_index_lists_sessions(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->affiliate();
        AffiliateUser::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $r->id, 'name' => 'S', 'email' => 's@acme.test',
            'password' => Hash::make('x'), 'is_active' => true,
        ]);
        $this->postJson("/api/affiliates/{$r->id}/impersonate");

        $this->getJson('/api/affiliate-impersonation-sessions')
            ->assertOk()
            ->assertJsonPath('sessions.0.affiliate', 'Acme Resell');
    }
}
