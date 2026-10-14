<?php

namespace Tests\Feature\Auth;

use App\Models\AdminUser;
use App\Services\Auth\AdminMfa;
use App\Services\Backup\BackupFailureAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** ADR-121: the cookie-session admin login and its MFA steps. */
class AdminMfaLoginTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Requests from the admin app are Sanctum-stateful (session + cookies).
        $this->withHeader('Referer', 'http://localhost:3000/');

        $alerter = Mockery::mock(BackupFailureAlerter::class);
        $alerter->shouldReceive('alert')->andReturnUsing(function (string $context) {
            $this->alerts[] = $context;
        });
        $this->app->instance(BackupFailureAlerter::class, $alerter);
    }

    private function login(AdminUser $admin, string $password = 'secret-password')
    {
        return $this->postJson('/api/login', ['email' => $admin->email, 'password' => $password]);
    }

    private function totp(AdminUser $admin, int $stepOffset = 0): string
    {
        $g = new Google2FA;

        return $g->oathTotp($admin->refresh()->mfa_secret, $g->getTimestamp() + $stepOffset);
    }

    private function activeMfaAdmin(): AdminUser
    {
        return AdminUser::factory()->create([
            'password' => 'secret-password',
            'mfa_required' => true,
            'mfa_enabled' => true,
            'mfa_secret' => (new Google2FA)->generateSecretKey(32),
        ]);
    }

    public function test_an_account_without_mfa_logs_in_with_a_password_and_gets_a_session_not_a_token(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);

        $this->login($admin)->assertOk()->assertJsonMissingPath('token')->assertJsonPath('admin.mfa_status', 'none');

        $this->getJson('/api/me')->assertOk()->assertJsonPath('email', $admin->email);
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_login_outside_the_admin_app_is_refused(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);

        $this->withHeader('Referer', 'http://localhost:3001/')->login($admin)->assertStatus(400);
    }

    public function test_an_admin_bearer_token_is_refused(): void
    {
        $admin = AdminUser::factory()->create();
        $token = $admin->createToken('api')->plainTextToken;

        $this->withHeaders(['Referer' => '', 'Authorization' => "Bearer {$token}"])
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_an_account_awaiting_setup_is_not_logged_in_until_it_confirms_a_code(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-password', 'mfa_required' => true]);

        $this->login($admin)->assertOk()
            ->assertJsonPath('mfa', 'setup')
            ->assertJsonStructure(['setup' => ['secret', 'qr_svg']]);

        $this->getJson('/api/me')->assertUnauthorized();

        $this->postJson('/api/login/mfa', ['code' => '000000'])->assertUnprocessable();
        $this->getJson('/api/me')->assertUnauthorized();

        $response = $this->postJson('/api/login/mfa', ['code' => $this->totp($admin)])->assertOk();

        $this->assertCount(8, $response->json('recovery_codes'));
        $this->assertSame('active', $response->json('admin.mfa_status'));
        $this->getJson('/api/me')->assertOk();
    }

    public function test_an_active_account_needs_a_code_and_a_code_cannot_be_used_twice(): void
    {
        $admin = $this->activeMfaAdmin();
        $code = $this->totp($admin);

        $this->login($admin)->assertOk()->assertJsonPath('mfa', 'challenge')->assertJsonMissingPath('setup');
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/login/mfa', ['code' => $code])->assertOk();

        $this->postJson('/api/logout')->assertOk();
        $this->login($admin)->assertOk();
        $this->postJson('/api/login/mfa', ['code' => $code])->assertUnprocessable();
    }

    public function test_a_recovery_code_works_once_and_alerts(): void
    {
        $admin = $this->activeMfaAdmin();
        $codes = app(AdminMfa::class)->regenerateRecoveryCodes($admin);

        $this->login($admin);
        $this->postJson('/api/login/mfa', ['code' => strtoupper($codes[0])])->assertOk();
        $this->assertSame(['Recovery code used'], $this->alerts);
        $this->assertCount(7, $admin->refresh()->mfa_recovery_codes);

        $this->postJson('/api/logout');
        $this->login($admin);
        $this->postJson('/api/login/mfa', ['code' => $codes[0]])->assertUnprocessable();
    }

    public function test_the_pending_login_expires_after_five_minutes(): void
    {
        $admin = $this->activeMfaAdmin();

        $this->login($admin);
        $this->travel(301)->seconds();

        $this->postJson('/api/login/mfa', ['code' => $this->totp($admin)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'This login has expired. Sign in again.');
    }

    public function test_ten_wrong_codes_lock_the_account_and_alert_once(): void
    {
        $admin = $this->activeMfaAdmin();

        for ($i = 1; $i <= 10; $i++) {
            // Fresh IP per attempt: the per-IP route throttle isn't what's under test.
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"]);
            $this->login($admin)->assertOk();
            $this->postJson('/api/login/mfa', ['code' => '000000'])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $this->login($admin)->assertStatus(429);
        $this->assertSame(['Account locked'], $this->alerts);
    }

    public function test_a_login_ends_after_seven_days_however_active(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);
        $this->login($admin)->assertOk();

        $this->travel(6)->days();
        $this->getJson('/api/me')->assertOk();

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_ends_the_session(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);
        $this->login($admin)->assertOk();

        $this->postJson('/api/logout')->assertOk();
        // The test app keeps resolved guards between requests; production
        // starts each request fresh.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_the_csrf_endpoint_returns_the_session_token(): void
    {
        $this->getJson('/api/csrf-token')->assertOk()->assertJsonStructure(['token']);
    }

    public function test_sanctum_acting_as_still_works_for_feature_tests(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create());

        $this->getJson('/api/me')->assertOk();
    }
}
