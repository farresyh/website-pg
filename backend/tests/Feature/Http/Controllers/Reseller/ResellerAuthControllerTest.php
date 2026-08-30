<?php

namespace Tests\Feature\Http\Controllers\Reseller;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\ResellerUser;
use App\Services\Reseller\ResellerInviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-058 (58a): reseller-portal auth on the separate `reseller` Sanctum
 * guard.
 */
class ResellerAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(array $overrides = []): Reseller
    {
        return Reseller::query()->create(array_merge([
            'business_name' => 'Acme Resell',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ], $overrides));
    }

    private function resellerUser(array $overrides = []): ResellerUser
    {
        $reseller = $overrides['reseller'] ?? $this->reseller();
        unset($overrides['reseller']);

        return ResellerUser::query()->create(array_merge([
            'reseller_id' => $reseller->id,
            'name' => 'Reseller Staff',
            'email' => 'staff@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ], $overrides));
    }

    public function test_login_returns_a_token_and_the_reseller_context(): void
    {
        $user = $this->resellerUser();

        $response = $this->postJson('/api/reseller/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'reseller_user' => ['id', 'reseller_id', 'name', 'email'], 'reseller' => ['id', 'business_name', 'status']]);
        $response->assertJsonMissingPath('reseller_user.password');
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_rejects_a_wrong_password_with_a_generic_error(): void
    {
        $user = $this->resellerUser();

        $this->postJson('/api/reseller/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_rejects_an_unknown_email_with_the_same_generic_error(): void
    {
        $this->postJson('/api/reseller/login', [
            'email' => 'nobody@acme.test',
            'password' => 'secret-password',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_rejects_a_user_whose_invite_is_not_yet_accepted(): void
    {
        // password is null until set-password — must not 500 on Hash::check(null).
        $user = $this->resellerUser(['password' => null]);

        $this->postJson('/api/reseller/login', [
            'email' => $user->email,
            'password' => 'anything',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_login_rejects_a_deactivated_user(): void
    {
        $user = $this->resellerUser(['is_active' => false]);

        $this->postJson('/api/reseller/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_me_returns_the_authenticated_reseller_user(): void
    {
        $user = $this->resellerUser();
        $token = $user->createToken('reseller')->plainTextToken;

        $this->withToken($token)->getJson('/api/reseller/me')
            ->assertOk()
            ->assertJsonPath('reseller_user.id', $user->id)
            ->assertJsonPath('reseller.id', $user->reseller_id);
    }

    public function test_an_admin_token_cannot_authenticate_a_reseller_route(): void
    {
        $admin = AdminUser::factory()->create();
        $token = $admin->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/reseller/me')->assertUnauthorized();
    }

    public function test_a_reseller_token_cannot_authenticate_an_admin_only_route(): void
    {
        $user = $this->resellerUser();
        $token = $user->createToken('reseller')->plainTextToken;

        // /api/admin-users is admin.role:super_admin — EnsureAdminRole's
        // `instanceof AdminUser` check rejects a ResellerUser.
        $this->withToken($token)->getJson('/api/admin-users')->assertForbidden();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->resellerUser();
        $token = $user->createToken('reseller')->plainTextToken;

        $this->withToken($token)->postJson('/api/reseller/logout')->assertOk();
        // Same assertion shape as the admin logout test — within one test
        // the Sanctum guard caches the resolved token, so re-hitting a
        // protected route is not a reliable revocation check.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_set_password_accepts_a_valid_invite_token_and_enables_login(): void
    {
        $user = $this->resellerUser(['password' => null]);
        $link = app(ResellerInviteService::class)->createInviteLink($user);
        parse_str(parse_url($link, PHP_URL_QUERY), $query);

        $this->postJson('/api/reseller/set-password', [
            'token' => $query['token'],
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->postJson('/api/reseller/login', [
            'email' => $user->email,
            'password' => 'brand-new-password',
        ])->assertOk();
    }

    public function test_set_password_rejects_a_bad_token(): void
    {
        $user = $this->resellerUser(['password' => null]);

        $this->postJson('/api/reseller/set-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422)->assertJsonValidationErrorFor('email');
    }

    public function test_invite_link_points_at_the_configured_reseller_portal_origin(): void
    {
        config(['services.reseller_portal.url' => 'https://portal.example.com']);
        $user = $this->resellerUser(['password' => null]);

        $link = app(ResellerInviteService::class)->createInviteLink($user);

        $this->assertStringStartsWith('https://portal.example.com/set-password?', $link);
    }
}
