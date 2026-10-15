<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_ok_when_database_and_queue_are_reachable(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('checks.database', true);
        $response->assertJsonPath('checks.queue', true);
        $response->assertJsonPath('checks.scheduler', true);
    }

    /**
     * 2026-09-29 pre-release review: with Horizon down nothing processes
     * orders, and its own LongWait alert dies with it — /api/health (the
     * uptime monitor's target) is the one signal left, so it must fail.
     */
    public function test_reports_degraded_when_the_redis_queue_has_no_running_horizon(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        $this->mock(MasterSupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andReturn([]));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.horizon', false);
    }

    public function test_reports_ok_when_horizon_is_running(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        $this->mock(MasterSupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andReturn([(object) ['name' => 'master']]));

        $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.horizon', true);
    }

    /**
     * ADR-122: a stale critical scheduled task fails the uptime monitor's
     * target; task names stay off this unauthenticated endpoint.
     */
    public function test_reports_degraded_when_a_critical_scheduled_task_is_stale(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        DB::table('scheduled_task_heartbeats')->insert(['name' => 'payment-reconciliation', 'created_at' => '2026-10-15 08:00:00', 'updated_at' => '2026-10-15 08:00:00']);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.scheduler', false)
            ->assertDontSee('payment-reconciliation');
    }

    public function test_a_stale_routine_task_does_not_fail_health(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        DB::table('scheduled_task_heartbeats')->insert(['name' => 'horizon-snapshot', 'created_at' => '2026-10-15 08:00:00', 'updated_at' => '2026-10-15 08:00:00']);

        $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.scheduler', true);
    }

    public function test_does_not_require_authentication(): void
    {
        $this->getJson('/api/health')->assertOk();
    }
}
