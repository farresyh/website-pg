<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_admin_users(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        AdminUser::factory()->count(2)->create();

        $response = $this->getJson('/api/admin-users');

        $response->assertOk();
        $response->assertJsonCount(3);
    }

    public function test_regular_admin_cannot_list_admin_users(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/admin-users');

        $response->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/admin-users');

        $response->assertUnauthorized();
    }

    public function test_super_admin_can_create_an_admin_user(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        $response = $this->postJson('/api/admin-users', [
            'name' => 'New Admin',
            'email' => 'new-admin@example.com',
            'password' => 'password123',
            'role' => 'admin',
            'phone' => '+60123456789',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('email', 'new-admin@example.com');
        $this->assertDatabaseHas('admin_users', ['email' => 'new-admin@example.com']);
    }

    public function test_create_rejects_a_duplicate_email(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        $existing = AdminUser::factory()->create();

        $response = $this->postJson('/api/admin-users', [
            'name' => 'Dup',
            'email' => $existing->email,
            'password' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertUnprocessable();
    }

    public function test_super_admin_can_update_an_admin_user_without_changing_password(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        $target = AdminUser::factory()->create(['name' => 'Old Name']);
        $originalPassword = $target->password;

        $response = $this->putJson("/api/admin-users/{$target->id}", [
            'name' => 'New Name',
            'email' => $target->email,
            'role' => 'admin',
        ]);

        $response->assertOk();
        $response->assertJsonPath('name', 'New Name');
        $this->assertSame($originalPassword, $target->fresh()->password);
    }

    public function test_super_admin_can_deactivate_another_admin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        $target = AdminUser::factory()->create(['is_active' => true]);

        $response = $this->patchJson("/api/admin-users/{$target->id}/status", [
            'is_active' => false,
        ]);

        $response->assertOk();
        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_super_admin_cannot_deactivate_their_own_account(): void
    {
        $self = AdminUser::factory()->superAdmin()->create(['is_active' => true]);
        Sanctum::actingAs($self);

        $response = $this->patchJson("/api/admin-users/{$self->id}/status", [
            'is_active' => false,
        ]);

        $response->assertUnprocessable();
        $this->assertTrue($self->fresh()->is_active);
    }
}
