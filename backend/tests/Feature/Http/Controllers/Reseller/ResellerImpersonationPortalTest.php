<?php

namespace Tests\Feature\Http\Controllers\Reseller;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\ResellerImpersonationSession;
use App\Models\ResellerUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-059 59c: the portal side of RES-4 impersonation — `/me` surfaces
 * the impersonation context for the banner, and `/impersonation/end`
 * lets the session close itself from inside the portal.
 */
class ResellerImpersonationPortalTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(): Reseller
    {
        return Reseller::query()->create([
            'business_name' => 'Acme', 'markup_pct' => 0, 'status' => 'active',
        ]);
    }

    private function resellerUser(Reseller $reseller): ResellerUser
    {
        return ResellerUser::query()->create([
            'reseller_id' => $reseller->id,
            'name' => 'Staff',
            'email' => 'staff@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);
    }

    /** Mints an impersonation-ability token + its open session row, as Admin\ResellerImpersonationController does. */
    private function impersonate(Reseller $reseller, ResellerUser $user): array
    {
        $admin = AdminUser::factory()->create(['name' => 'Boss Admin']);
        $newToken = $user->createToken('impersonation', ['impersonate'], now()->addHour());

        $session = ResellerImpersonationSession::query()->create([
            'reseller_id' => $reseller->id,
            'admin_user_id' => $admin->id,
            'reseller_user_id' => $user->id,
            'personal_access_token_id' => $newToken->accessToken->id,
            'ip' => '127.0.0.1',
            'started_at' => now(),
        ]);

        return [$newToken->plainTextToken, $session];
    }

    public function test_me_returns_null_impersonation_for_a_normal_login_token(): void
    {
        $user = $this->resellerUser($this->reseller());
        $token = $user->createToken('reseller')->plainTextToken;

        $this->withToken($token)->getJson('/api/reseller/me')
            ->assertOk()
            ->assertJsonPath('impersonation', null);
    }

    public function test_me_returns_the_impersonation_context_for_an_impersonation_token(): void
    {
        $reseller = $this->reseller();
        [$token, $session] = $this->impersonate($reseller, $this->resellerUser($reseller));

        $this->withToken($token)->getJson('/api/reseller/me')
            ->assertOk()
            ->assertJsonPath('impersonation.session_id', $session->id)
            ->assertJsonPath('impersonation.admin_name', 'Boss Admin');
    }

    public function test_end_closes_the_session_and_revokes_the_token(): void
    {
        $reseller = $this->reseller();
        [$token, $session] = $this->impersonate($reseller, $this->resellerUser($reseller));

        $this->withToken($token)->postJson('/api/reseller/impersonation/end')->assertOk();

        $this->assertNotNull($session->fresh()->ended_at);
        $this->assertSame('manual', $session->fresh()->ended_reason);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_end_is_forbidden_for_a_non_impersonation_token(): void
    {
        $user = $this->resellerUser($this->reseller());
        $token = $user->createToken('reseller')->plainTextToken;

        $this->withToken($token)->postJson('/api/reseller/impersonation/end')->assertForbidden();
    }
}
