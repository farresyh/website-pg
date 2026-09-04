<?php

namespace Tests\Feature\Console;

use App\Models\ResellerWhatsAppPendingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PR-F build addendum decision 3 — a pending-link row past its 24h TTL is pruned. */
class PruneResellerWhatsAppPendingLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    private function pending(string $groupId, \DateTimeInterface|string $lastMessageAt): ResellerWhatsAppPendingLink
    {
        return ResellerWhatsAppPendingLink::query()->create([
            'whatsapp_group_id' => $groupId,
            'last_message_preview' => 'hi',
            'last_message_at' => $lastMessageAt,
        ]);
    }

    public function test_deletes_a_pending_link_past_the_ttl(): void
    {
        config(['services.openwa.pending_link_ttl_hours' => 24]);
        $this->pending('stale@g.us', now()->subHours(25));

        $this->artisan('app:prune-reseller-whatsapp-pending-links')->assertExitCode(0);

        $this->assertSame(0, ResellerWhatsAppPendingLink::query()->count());
    }

    public function test_keeps_a_pending_link_within_the_ttl(): void
    {
        config(['services.openwa.pending_link_ttl_hours' => 24]);
        $this->pending('fresh@g.us', now()->subHours(1));

        $this->artisan('app:prune-reseller-whatsapp-pending-links')->assertExitCode(0);

        $this->assertSame(1, ResellerWhatsAppPendingLink::query()->count());
    }
}
