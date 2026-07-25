<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_does_not_require_authentication(): void
    {
        $this->getJson('/api/health')->assertOk();
    }
}
