<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_does_not_require_authentication(): void
    {
        $this->getJson('/api/health')->assertOk();
    }
}
