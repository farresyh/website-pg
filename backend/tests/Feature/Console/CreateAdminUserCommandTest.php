<?php

namespace Tests\Feature\Console;

use App\Models\AdminUser;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_super_admin_from_config_fallback(): void
    {
        config([
            'admin.seed_email' => 'founder@pekangame.space',
            'admin.seed_password' => 'super-secret-pw',
            'admin.seed_name' => 'Founder',
        ]);

        $this->artisan('app:create-admin')->assertExitCode(0);

        $admin = AdminUser::query()->where('email', 'founder@pekangame.space')->sole();
        $this->assertSame('Founder', $admin->name);
        $this->assertSame('super_admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('super-secret-pw', $admin->password));
    }

    public function test_options_override_config(): void
    {
        config(['admin.seed_email' => 'config@example.com', 'admin.seed_password' => 'config-password']);

        $this->artisan('app:create-admin', [
            '--email' => 'opt@example.com',
            '--password' => 'option-password',
            '--name' => 'Option Name',
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('admin_users', ['email' => 'config@example.com']);
        $this->assertDatabaseHas('admin_users', ['email' => 'opt@example.com', 'name' => 'Option Name', 'role' => 'super_admin']);
    }

    public function test_is_idempotent_and_leaves_an_existing_account_untouched_without_force(): void
    {
        $admin = AdminUser::factory()->create([
            'email' => 'existing@example.com',
            'password' => Hash::make('original-password'),
            'role' => 'admin',
            'is_active' => false,
        ]);

        config(['admin.seed_email' => 'existing@example.com', 'admin.seed_password' => 'a-new-password']);

        $this->artisan('app:create-admin')->assertExitCode(0);

        $admin->refresh();
        $this->assertSame('admin', $admin->role);
        $this->assertFalse($admin->is_active);
        $this->assertTrue(Hash::check('original-password', $admin->password));
    }

    public function test_force_resets_password_reactivates_and_regrants_super_admin(): void
    {
        $admin = AdminUser::factory()->create([
            'email' => 'existing@example.com',
            'password' => Hash::make('original-password'),
            'role' => 'admin',
            'is_active' => false,
        ]);

        $this->artisan('app:create-admin', [
            '--email' => 'existing@example.com',
            '--password' => 'the-reset-password',
            '--force' => true,
        ])->assertExitCode(0);

        $admin->refresh();
        $this->assertSame('super_admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('the-reset-password', $admin->password));
    }

    public function test_skips_with_exit_zero_when_no_email_or_password_is_available(): void
    {
        config(['admin.seed_email' => null, 'admin.seed_password' => null]);

        $this->artisan('app:create-admin')
            ->expectsOutputToContain('skipping')
            ->assertExitCode(0);

        $this->assertSame(0, AdminUser::query()->count());
    }

    public function test_fails_on_an_invalid_password(): void
    {
        $this->artisan('app:create-admin', [
            '--email' => 'someone@example.com',
            '--password' => 'short',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('admin_users', ['email' => 'someone@example.com']);
    }

    public function test_production_seeder_creates_the_super_admin_from_config(): void
    {
        config([
            'admin.seed_email' => 'prod-admin@pekangame.space',
            'admin.seed_password' => 'prod-admin-password',
        ]);

        $this->seed(ProductionSeeder::class);

        $this->assertDatabaseHas('admin_users', [
            'email' => 'prod-admin@pekangame.space',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
