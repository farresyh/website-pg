<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        $admin = AdminUser::factory()->create(['password' => 'secret-password']);

        $response = $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'admin']);
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

    public function test_logout_revokes_the_current_token(): void
    {
        $admin = AdminUser::factory()->create();
        $token = $admin->createToken('api');

        $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->postJson('/api/logout');

        $response->assertOk();
        $this->assertSame(0, $admin->tokens()->count());
    }
}
