<?php

namespace Tests\Feature\Listeners\Horizon;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Events\LongWaitDetected;
use Tests\TestCase;

/**
 * ADR-048 addendum (2026-09-29, audit Wave 4 Low): Horizon's own mail
 * notification rides the `Mail::` facade, which is `log` in production
 * (see BackupFailureAlerter) — so a long order-queue wait must alert via
 * Plunk, throttled so a stuck queue doesn't mail every minute.
 */
class AlertOnLongQueueWaitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_long_wait_alerts_active_admins_once_per_queue_window(): void
    {
        Http::fake(['next-api.useplunk.com/*' => Http::response(['success' => true], 200)]);
        AdminUser::factory()->create(['email' => 'ops@example.com', 'is_active' => true]);

        event(new LongWaitDetected('redis', 'orders', 120));
        event(new LongWaitDetected('redis', 'orders', 180));
        event(new LongWaitDetected('redis', 'orders-reseller', 90));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['to'] === 'ops@example.com' && str_contains($request['subject'], 'redis:orders '));
        Http::assertSent(fn ($request) => str_contains($request['subject'], 'redis:orders-reseller'));
    }
}
