<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\ResellerImpersonationSession;
use App\Models\ResellerUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-058 58b (RES-4): admin impersonation of a reseller portal session.
 */
class ResellerImpersonationControllerTest extends TestCase
{
    use RefreshDatabase;

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
            'status' => 'active',
        ], $overrides));
    }

    public function test_start_mints_scoped_expiring_token_and_audits(): void
    {
        $admin = $this->actAsSuperAdmin();
        $r = $this->reseller();
        $user = ResellerUser::query()->create([
            'reseller_id' => $r->id,
            'name' => 'Staff',
            'email' => 'staff@acme.test',
            'password' => Hash::make('x'),
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/resellers/{$r->id}/impersonate", ['reason' => 'debugging']);

        $response->assertCreated();
        $response->assertJsonStructure(['session_id', 'token', 'acting_as' => ['id', 'email'], 'portal_url', 'expires_at']);

        $this->assertDatabaseHas('reseller_impersonation_sessions', [
            'reseller_id' => $r->id,
            'admin_user_id' => $admin->id,
            'reseller_user_id' => $user->id,
            'ended_at' => null,
        ]);

        $tokenId = ResellerImpersonationSession::query()->first()->personal_access_token_id;
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId, 'abilities' => '["impersonate"]']);
        $this->assertNotNull(PersonalAccessToken::query()->find($tokenId)->expires_at);
    }

    public function test_start_rejected_when_reseller_has_no_active_user(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller();

        $this->postJson("/api/resellers/{$r->id}/impersonate")->assertUnprocessable();
    }

    public function test_start_rejected_when_reseller_inactive(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller(['status' => 'inactive']);
        ResellerUser::query()->create([
            'reseller_id' => $r->id, 'name' => 'S', 'email' => 's@acme.test',
            'password' => Hash::make('x'), 'is_active' => true,
        ]);

        $this->postJson("/api/resellers/{$r->id}/impersonate")->assertUnprocessable();
    }

    public function test_end_revokes_token_and_closes_session(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller();
        ResellerUser::query()->create([
            'reseller_id' => $r->id, 'name' => 'S', 'email' => 's@acme.test',
            'password' => Hash::make('x'), 'is_active' => true,
        ]);

        $sessionId = $this->postJson("/api/resellers/{$r->id}/impersonate")->json('session_id');
        $tokenId = ResellerImpersonationSession::query()->find($sessionId)->personal_access_token_id;

        $this->postJson("/api/reseller-impersonation-sessions/{$sessionId}/end")->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertNotNull(ResellerImpersonationSession::query()->find($sessionId)->ended_at);
    }

    public function test_deactivating_reseller_ends_live_impersonation(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller();
        ResellerUser::query()->create([
            'reseller_id' => $r->id, 'name' => 'S', 'email' => 's@acme.test',
            'password' => Hash::make('x'), 'is_active' => true,
        ]);
        $sessionId = $this->postJson("/api/resellers/{$r->id}/impersonate")->json('session_id');

        $this->patchJson("/api/resellers/{$r->id}/status", ['status' => 'inactive'])->assertOk();

        $session = ResellerImpersonationSession::query()->find($sessionId);
        $this->assertSame('reseller_deactivated', $session->ended_reason);
        $this->assertNotNull($session->ended_at);
    }

    public function test_index_lists_sessions(): void
    {
        $this->actAsSuperAdmin();
        $r = $this->reseller();
        ResellerUser::query()->create([
            'reseller_id' => $r->id, 'name' => 'S', 'email' => 's@acme.test',
            'password' => Hash::make('x'), 'is_active' => true,
        ]);
        $this->postJson("/api/resellers/{$r->id}/impersonate");

        $this->getJson('/api/reseller-impersonation-sessions')
            ->assertOk()
            ->assertJsonPath('sessions.0.reseller', 'Acme Resell');
    }
}
