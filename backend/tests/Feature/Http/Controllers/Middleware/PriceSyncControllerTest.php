<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Jobs\SyncSupplierPricesJob;
use App\Models\AdminUser;
use App\Models\PriceSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PriceSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/middleware/price-sync')->assertUnauthorized();
    }

    /**
     * ADR-015 decision #5: "Sync All Prices Now" never blocks on a
     * live Gamevion call — it only ever queues a run.
     */
    public function test_store_creates_a_queued_run_and_dispatches_the_job(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/price-sync');

        $response->assertCreated();
        $response->assertJsonPath('status', 'queued');

        $this->assertSame(1, PriceSyncRun::query()->count());
        Queue::assertPushed(SyncSupplierPricesJob::class, fn (SyncSupplierPricesJob $job) => $job->run->id === PriceSyncRun::query()->firstOrFail()->id);
    }

    /**
     * ADR-016 decision #5: `triggered_by` distinguishes a manual run
     * from a scheduled one — Sync History (once built) needs this to
     * show who started each run.
     */
    public function test_store_records_the_triggering_admins_name(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/middleware/price-sync')->assertCreated();

        $this->assertSame($admin->name, PriceSyncRun::query()->firstOrFail()->triggered_by);
    }

    public function test_show_returns_a_runs_current_status(): void
    {
        $run = PriceSyncRun::query()->create([
            'status' => 'success',
            'stats' => ['price_changed' => 2, 'deactivated' => 1],
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/middleware/price-sync/runs/{$run->id}");

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('stats.price_changed', 2);
    }
}
