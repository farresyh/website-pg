<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\AdminUser;
use App\Services\Backup\BackupFailureAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ADR-121: login is a cookie session, available only to the admin app
     * (a Sanctum stateful origin). Session, MFA and logout behaviour live
     * in Tests\Feature\Auth\AdminMfaLoginTest.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost:3000/');
    }

    public function test_login_logs_a_successful_attempt(): void
    {
        Log::spy();
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);

        $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ]);

        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Admin login succeeded' && $context['admin_id'] === $admin->id,
        );
    }

    public function test_login_rate_limits_repeated_requests_from_the_same_ip(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => $admin->email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    /**
     * Wave 3 PR-B (ADR-019 addendum): the per-IP bucket alone doesn't stop
     * a distributed guess against ONE account. 10 failures in 15 minutes
     * from any mix of IPs locks that account for 15 minutes — even the
     * correct password is refused until it expires.
     */
    public function test_login_locks_an_account_after_10_failures_from_any_ips(): void
    {
        // The lockout alert goes out through Plunk; never let a test reach the real API (it once sent real emails).
        Http::fake(['next-api.useplunk.com/*' => Http::response(['success' => true], 200)]);
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);

        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
                ->postJson('/api/login', ['email' => $admin->email, 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->postJson('/api/login', ['email' => $admin->email, 'password' => 'secret-password'])
            ->assertStatus(429);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'useplunk.com') && str_contains($request['subject'] ?? '', 'Account locked'));

        $this->travel(16)->minutes();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])
            ->postJson('/api/login', ['email' => $admin->email, 'password' => 'secret-password'])
            ->assertOk();
    }

    public function test_a_successful_login_resets_the_account_failure_count(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);
        $attempt = fn (int $i, string $password) => $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
            ->postJson('/api/login', ['email' => $admin->email, 'password' => $password]);

        for ($i = 1; $i <= 9; $i++) {
            $attempt($i, 'wrong-password')->assertUnprocessable();
        }
        $attempt(20, 'secret-password')->assertOk();

        $attempt(21, 'wrong-password')->assertUnprocessable();
        $attempt(22, 'secret-password')->assertOk();
    }

    /**
     * Found live, 2026-08-27: ThrottleRequests' default key is
     * sha1($route->getDomain().'|'.$request->ip()) — no route path at
     * all — so every throttle:N,1 route without its own prefix shares
     * one bucket per IP. A guest hitting /client-errors repeatedly was
     * silently eating into /login's 5/minute budget from the same IP.
     * Every throttle: route in routes/api.php now carries its own
     * prefix (`throttle:5,1,login`, `throttle:30,1,client-errors`,
     * etc.) precisely so this can't happen — this test pins that.
     */
    public function test_login_rate_limit_is_not_shared_with_other_throttled_routes(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/client-errors', [
                'schema' => 'Test',
                'path' => '/test',
                'error' => 'test drift',
            ]);
        }

        $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertOk();
    }

    public function test_login_rejects_wrong_password(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);

        $response = $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
    }

    public function test_login_logs_a_failed_attempt_with_wrong_password(): void
    {
        Log::spy();
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);

        $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ]);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Admin login failed: invalid credentials' && $context['email'] === $admin->email,
        );
    }

    public function test_login_rejects_a_deactivated_account(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-password', 'is_active' => false]);

        $response = $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ]);

        $response->assertUnprocessable();
    }

    public function test_login_logs_a_deactivated_account_attempt(): void
    {
        Log::spy();
        $admin = AdminUser::factory()->create(['password' => 'secret-password', 'is_active' => false]);

        $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ]);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Admin login failed: account deactivated' && $context['admin_id'] === $admin->id,
        );
    }

    public function test_me_returns_the_authenticated_admin(): void
    {
        $admin = AdminUser::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/me');

        $response->assertOk();
        $response->assertJsonPath('email', $admin->email);
    }

    public function test_me_rejects_an_unauthenticated_request(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertUnauthorized();
    }

    /**
     * A password-guess lockout emails every admin, but only for a real
     * account: a lock on an unknown email stays a log line, so an attacker
     * cycling made-up emails can't flood the inbox.
     */
    private function captureAlerts(): \ArrayObject
    {
        $alerts = new \ArrayObject;
        $alerter = Mockery::mock(BackupFailureAlerter::class);
        $alerter->shouldReceive('alert')->andReturnUsing(function (string $context, string $message, string $area) use ($alerts) {
            $alerts[] = "{$area}|{$context}|{$message}";
        });
        $this->app->instance(BackupFailureAlerter::class, $alerter);

        return $alerts;
    }

    public function test_a_password_lockout_alerts_once_for_a_real_account(): void
    {
        $alerts = $this->captureAlerts();
        $user = AdminUser::factory()->create(['password' => 'secret-password']);

        for ($i = 1; $i <= 11; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
                ->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->assertCount(1, $alerts);
        $this->assertStringStartsWith('Security|Account locked|', $alerts[0]);
        $this->assertStringContainsString($user->email, $alerts[0]);
        $this->assertStringContainsString('203.0.113.10', $alerts[0]);
    }

    public function test_a_lockout_on_an_unknown_email_does_not_alert(): void
    {
        $alerts = $this->captureAlerts();

        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
                ->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
                ->assertStatus(422);
        }

        $this->assertCount(0, $alerts);
    }
}
