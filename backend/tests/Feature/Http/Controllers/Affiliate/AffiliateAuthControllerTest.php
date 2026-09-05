<?php

namespace Tests\Feature\Http\Controllers\Affiliate;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateUser;
use App\Models\Reseller;
use App\Services\Affiliate\AffiliateInviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-058 (58a): affiliate-portal auth on the separate `affiliate` Sanctum
 * guard. ADR-072 decision 5 / PR-G: this exact same endpoint also
 * authenticates a `Reseller` (wallet) portal account (owner_type =
 * 'reseller') — see the `reseller_*` tests at the bottom of this file.
 */
class AffiliateAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private function affiliate(array $overrides = []): Affiliate
    {
        return Affiliate::query()->create(array_merge([
            'business_name' => 'Acme Resell',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ], $overrides));
    }

    private function affiliateUser(array $overrides = []): AffiliateUser
    {
        $affiliate = $overrides['affiliate'] ?? $this->affiliate();
        unset($overrides['affiliate']);

        return AffiliateUser::query()->create(array_merge([
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'name' => 'Affiliate Staff',
            'email' => 'staff@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ], $overrides));
    }

    public function test_login_returns_a_token_and_the_affiliate_context(): void
    {
        $user = $this->affiliateUser();

        $response = $this->postJson('/api/affiliate/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'affiliate_user' => ['id', 'owner_type', 'owner_id', 'name', 'email'], 'affiliate' => ['id', 'business_name', 'status']]);
        $response->assertJsonPath('affiliate_user.owner_type', 'affiliate');
        $response->assertJsonPath('reseller', null);
        $response->assertJsonMissingPath('affiliate_user.password');
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_rejects_a_wrong_password_with_a_generic_error(): void
    {
        $user = $this->affiliateUser();

        $this->postJson('/api/affiliate/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_rejects_an_unknown_email_with_the_same_generic_error(): void
    {
        $this->postJson('/api/affiliate/login', [
            'email' => 'nobody@acme.test',
            'password' => 'secret-password',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_rejects_a_user_whose_invite_is_not_yet_accepted(): void
    {
        // password is null until set-password — must not 500 on Hash::check(null).
        $user = $this->affiliateUser(['password' => null]);

        $this->postJson('/api/affiliate/login', [
            'email' => $user->email,
            'password' => 'anything',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_rejects_a_deactivated_user(): void
    {
        $user = $this->affiliateUser(['is_active' => false]);

        $this->postJson('/api/affiliate/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_me_returns_the_authenticated_affiliate_user(): void
    {
        $user = $this->affiliateUser();
        $token = $user->createToken('affiliate')->plainTextToken;

        $this->withToken($token)->getJson('/api/affiliate/me')
            ->assertOk()
            ->assertJsonPath('affiliate_user.id', $user->id)
            ->assertJsonPath('affiliate.id', $user->owner_id);
    }

    public function test_an_admin_token_cannot_authenticate_a_affiliate_route(): void
    {
        $admin = AdminUser::factory()->create();
        $token = $admin->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/affiliate/me')->assertUnauthorized();
    }

    public function test_a_affiliate_token_cannot_authenticate_an_admin_only_route(): void
    {
        $user = $this->affiliateUser();
        $token = $user->createToken('affiliate')->plainTextToken;

        // /api/admin-users is admin.role:super_admin — EnsureAdminRole's
        // `instanceof AdminUser` check rejects an AffiliateUser.
        $this->withToken($token)->getJson('/api/admin-users')->assertForbidden();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->affiliateUser();
        $token = $user->createToken('affiliate')->plainTextToken;

        $this->withToken($token)->postJson('/api/affiliate/logout')->assertOk();
        // Same assertion shape as the admin logout test — within one test
        // the Sanctum guard caches the resolved token, so re-hitting a
        // protected route is not a reliable revocation check.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_set_password_accepts_a_valid_invite_token_and_enables_login(): void
    {
        $user = $this->affiliateUser(['password' => null]);
        $link = app(AffiliateInviteService::class)->createInviteLink($user);
        parse_str(parse_url($link, PHP_URL_QUERY), $query);

        $this->postJson('/api/affiliate/set-password', [
            'token' => $query['token'],
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->postJson('/api/affiliate/login', [
            'email' => $user->email,
            'password' => 'brand-new-password',
        ])->assertOk();
    }

    public function test_set_password_rejects_a_bad_token(): void
    {
        $user = $this->affiliateUser(['password' => null]);

        $this->postJson('/api/affiliate/set-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_invite_link_points_at_the_configured_affiliate_portal_origin(): void
    {
        config(['services.affiliate_portal.url' => 'https://portal.example.com']);
        $user = $this->affiliateUser(['password' => null]);

        $link = app(AffiliateInviteService::class)->createInviteLink($user);

        $this->assertStringStartsWith('https://portal.example.com/set-password?', $link);
    }

    // --- ADR-072 decision 5 / PR-G: a Reseller (wallet) account, same endpoint. ---

    private function reseller(array $overrides = []): Reseller
    {
        return Reseller::query()->create(array_merge([
            'business_name' => 'Wallet Reseller',
            'is_active' => true,
        ], $overrides));
    }

    private function resellerUser(array $overrides = []): AffiliateUser
    {
        $reseller = $overrides['reseller'] ?? $this->reseller();
        unset($overrides['reseller']);

        return AffiliateUser::query()->create(array_merge([
            'owner_type' => 'reseller',
            'owner_id' => $reseller->id,
            'name' => 'Reseller Staff',
            'email' => 'staff@wallet-reseller.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ], $overrides));
    }

    public function test_login_authenticates_a_reseller_wallet_account_through_the_same_endpoint(): void
    {
        $user = $this->resellerUser();

        $response = $this->postJson('/api/affiliate/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('affiliate_user.owner_type', 'reseller');
        $response->assertJsonPath('affiliate', null);
        $response->assertJsonPath('reseller.business_name', 'Wallet Reseller');
    }

    public function test_me_returns_the_reseller_context_for_a_reseller_owned_account(): void
    {
        $user = $this->resellerUser();
        $token = $user->createToken('affiliate')->plainTextToken;

        $this->withToken($token)->getJson('/api/affiliate/me')
            ->assertOk()
            ->assertJsonPath('affiliate', null)
            ->assertJsonPath('reseller.id', $user->owner_id)
            ->assertJsonPath('impersonation', null);
    }
}
