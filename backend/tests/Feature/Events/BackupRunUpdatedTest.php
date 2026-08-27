<?php

namespace Tests\Feature\Events;

use App\Events\BackupRunUpdated;
use App\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BackupRunUpdatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_status_broadcasts_backup_run_updated(): void
    {
        Event::fake([BackupRunUpdated::class]);

        $run = BackupRun::query()->create(['status' => 'queued', 'triggered_by' => 'Test Admin']);
        $run->update(['status' => 'running']);

        Event::assertDispatched(
            BackupRunUpdated::class,
            fn (BackupRunUpdated $event) => $event->run->is($run),
        );
    }

    public function test_updating_an_unrelated_field_does_not_broadcast(): void
    {
        Event::fake([BackupRunUpdated::class]);

        $run = BackupRun::query()->create(['status' => 'queued', 'triggered_by' => 'Test Admin']);
        $run->update(['triggered_by' => 'Someone Else']);

        Event::assertNotDispatched(BackupRunUpdated::class);
    }

    public function test_broadcasts_on_the_shared_admin_wide_private_channel(): void
    {
        $run = BackupRun::query()->create(['status' => 'queued', 'triggered_by' => 'Test Admin']);

        $channels = (new BackupRunUpdated($run))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-backups', $channels[0]->name);
    }
}
