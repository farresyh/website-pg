<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    public function test_health_returns_system_health_with_definitions(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/dashboard/health');

        $response->assertOk()->assertJsonStructure([
            'suppliers',
            'suppliers_definition',
            'stuck_orders' => ['value', 'definition'],
            'pending_payments' => ['value', 'definition'],
            'queue' => ['pending', 'failed', 'definition'],
            'scheduled_tasks' => ['tasks' => [['name', 'tier', 'stale', 'last_ok_at', 'last_failed_at', 'last_error', 'last_runtime_seconds']], 'definition'],
        ]);
    }
}
