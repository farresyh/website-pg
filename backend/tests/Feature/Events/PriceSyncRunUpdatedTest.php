<?php

namespace Tests\Feature\Events;

use App\Events\PriceSyncRunUpdated;
use App\Models\PriceSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PriceSyncRunUpdatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_status_broadcasts_price_sync_run_updated(): void
    {
        Event::fake([PriceSyncRunUpdated::class]);

        $run = PriceSyncRun::query()->create(['status' => 'queued', 'triggered_by' => 'Test Admin']);
        $run->update(['status' => 'running']);

        Event::assertDispatched(
            PriceSyncRunUpdated::class,
            fn (PriceSyncRunUpdated $event) => $event->run->is($run),
        );
    }

    public function test_updating_an_unrelated_field_does_not_broadcast(): void
    {
        Event::fake([PriceSyncRunUpdated::class]);

        $run = PriceSyncRun::query()->create(['status' => 'queued', 'triggered_by' => 'Test Admin']);
        $run->update(['triggered_by' => 'Someone Else']);

        Event::assertNotDispatched(PriceSyncRunUpdated::class);
    }

    public function test_broadcasts_on_a_private_channel_scoped_to_the_run(): void
    {
        $run = PriceSyncRun::query()->create(['status' => 'queued', 'triggered_by' => 'Test Admin']);

        $channels = (new PriceSyncRunUpdated($run))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-price-sync-run.'.$run->id, $channels[0]->name);
    }
}
