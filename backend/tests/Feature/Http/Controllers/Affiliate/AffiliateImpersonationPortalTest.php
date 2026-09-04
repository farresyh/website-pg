<?php

namespace Tests\Feature\Http\Controllers\Affiliate;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateImpersonationSession;
use App\Models\AffiliateUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-059 59c: the portal side of RES-4 impersonation — `/me` surfaces
 * the impersonation context for the banner, and `/impersonation/end`
 * lets the session close itself from inside the portal.
 */
class AffiliateImpersonationPortalTest extends TestCase
{
    use RefreshDatabase;

    private function affiliate(): Affiliate
    {
        return Affiliate::query()->create([
            'business_name' => 'Acme', 'markup_pct' => 0, 'status' => 'active',
        ]);
    }

    private function affiliateUser(Affiliate $affiliate): AffiliateUser
    {
        return AffiliateUser::query()->create([
            'affiliate_id' => $affiliate->id,
            'name' => 'Staff',
            'email' => 'staff@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);
    }

    /** Mints an impersonation-ability token + its open session row, as Admin\AffiliateImpersonationController does. */
    private function impersonate(Affiliate $affiliate, AffiliateUser $user): array
    {
        $admin = AdminUser::factory()->create(['name' => 'Boss Admin']);
        $newToken = $user->createToken('impersonation', ['impersonate'], now()->addHour());

        $session = AffiliateImpersonationSession::query()->create([
            'affiliate_id' => $affiliate->id,
            'admin_user_id' => $admin->id,
            'affiliate_user_id' => $user->id,
            'personal_access_token_id' => $newToken->accessToken->id,
            'ip' => '127.0.0.1',
            'started_at' => now(),
        ]);

        return [$newToken->plainTextToken, $session];
    }

    public function test_me_returns_null_impersonation_for_a_normal_login_token(): void
    {
        $user = $this->affiliateUser($this->affiliate());
        $token = $user->createToken('affiliate')->plainTextToken;

        $this->withToken($token)->getJson('/api/affiliate/me')
            ->assertOk()
            ->assertJsonPath('impersonation', null);
    }

    public function test_me_returns_the_impersonation_context_for_an_impersonation_token(): void
    {
        $affiliate = $this->affiliate();
        [$token, $session] = $this->impersonate($affiliate, $this->affiliateUser($affiliate));

        $this->withToken($token)->getJson('/api/affiliate/me')
            ->assertOk()
            ->assertJsonPath('impersonation.session_id', $session->id)
            ->assertJsonPath('impersonation.admin_name', 'Boss Admin');
    }

    public function test_end_closes_the_session_and_revokes_the_token(): void
    {
        $affiliate = $this->affiliate();
        [$token, $session] = $this->impersonate($affiliate, $this->affiliateUser($affiliate));

        $this->withToken($token)->postJson('/api/affiliate/impersonation/end')->assertOk();

        $this->assertNotNull($session->fresh()->ended_at);
        $this->assertSame('manual', $session->fresh()->ended_reason);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_end_is_forbidden_for_a_non_impersonation_token(): void
    {
        $user = $this->affiliateUser($this->affiliate());
        $token = $user->createToken('affiliate')->plainTextToken;

        $this->withToken($token)->postJson('/api/affiliate/impersonation/end')->assertForbidden();
    }
}
