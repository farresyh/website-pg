<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Jobs\RunDatabaseBackupJob;
use App\Models\AdminUser;
use App\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-039 decisions 9/10: `/middleware/backups` — super_admin-only,
 * "Backup Now" only ever queues (never blocks the request on the real
 * dump), no restore endpoint anywhere here (decision 5).
 */
class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/middleware/backups')->assertUnauthorized();
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/middleware/backups')->assertForbidden();
        $this->postJson('/api/middleware/backups')->assertForbidden();
    }

    public function test_store_creates_a_queued_run_and_dispatches_the_job(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/backups');

        $response->assertCreated();
        $response->assertJsonPath('status', 'queued');

        $this->assertSame(1, BackupRun::query()->count());
        Queue::assertPushed(
            RunDatabaseBackupJob::class,
            fn (RunDatabaseBackupJob $job) => $job->run->id === BackupRun::query()->firstOrFail()->id,
        );
    }

    /**
     * ADR-039 decision 9: `triggered_by` distinguishes a manual run
     * (admin's name) from a scheduled one ('system').
     */
    public function test_store_records_the_triggering_admins_name(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/middleware/backups')->assertCreated();

        $this->assertSame($admin->name, BackupRun::query()->firstOrFail()->triggered_by);
    }

    public function test_index_lists_runs_most_recent_first(): void
    {
        $this->actingAsAdmin();

        $older = BackupRun::query()->create(['status' => 'success', 'triggered_by' => 'system', 'created_at' => now()->subDay()]);
        $newer = BackupRun::query()->create(['status' => 'success', 'triggered_by' => 'system']);

        $response = $this->getJson('/api/middleware/backups');

        $response->assertOk();
        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
    }

    public function test_stats_reports_count_and_total_size(): void
    {
        $this->actingAsAdmin();

        BackupRun::query()->create(['status' => 'success', 'triggered_by' => 'system', 'size_bytes' => 1000]);
        BackupRun::query()->create(['status' => 'failed', 'triggered_by' => 'system']);

        $response = $this->getJson('/api/middleware/backups/stats');

        $response->assertOk();
        $response->assertJsonPath('total_count', 2);
        $response->assertJsonPath('total_size_bytes', 1000);
    }

    public function test_download_returns_404_when_run_has_no_archive(): void
    {
        $this->actingAsAdmin();

        $run = BackupRun::query()->create(['status' => 'failed', 'triggered_by' => 'system']);

        $this->getJson("/api/middleware/backups/{$run->id}/download")->assertNotFound();
    }

    public function test_download_streams_the_archive_from_its_disk(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('pekangame/backup.zip', 'fake-zip-contents');
        $this->actingAsAdmin();

        $run = BackupRun::query()->create([
            'status' => 'success',
            'triggered_by' => 'system',
            'disk' => 'local',
            'path' => 'pekangame/backup.zip',
        ]);

        $this->get("/api/middleware/backups/{$run->id}/download")->assertOk();
    }

    public function test_destroy_deletes_the_archive_and_the_run(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('pekangame/backup.zip', 'fake-zip-contents');
        $this->actingAsAdmin();

        $run = BackupRun::query()->create([
            'status' => 'success',
            'triggered_by' => 'system',
            'disk' => 'local',
            'path' => 'pekangame/backup.zip',
        ]);

        $this->deleteJson("/api/middleware/backups/{$run->id}")->assertNoContent();

        Storage::disk('local')->assertMissing('pekangame/backup.zip');
        $this->assertSame(0, BackupRun::query()->count());
    }

    public function test_no_restore_route_exists(): void
    {
        $this->actingAsAdmin();

        $run = BackupRun::query()->create(['status' => 'success', 'triggered_by' => 'system']);

        $this->postJson("/api/middleware/backups/{$run->id}/restore")->assertNotFound();
    }
}
