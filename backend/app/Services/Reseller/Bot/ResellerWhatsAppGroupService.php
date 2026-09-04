<?php

namespace App\Services\Reseller\Bot;

use App\Models\Reseller;
use App\Models\ResellerWhatsAppGroup;
use App\Models\ResellerWhatsAppPendingLink;
use Illuminate\Support\Str;

/**
 * ADR-075 decision 2 / PR-F build addendum decision 3 — the one seam
 * for resolving a WhatsApp group to its `Reseller` (wallet) account and
 * for the admin-linking mechanism: an unmapped group's message becomes
 * a short-lived pending row (`reseller_whatsapp_pending_links`, 24h TTL)
 * instead of being silently dropped, until an admin links it.
 */
final class ResellerWhatsAppGroupService
{
    /** Null for an unmapped or deliberately deactivated (unlinked) group — the two are indistinguishable to a caller, both mean "no active reseller here". */
    public function resolveReseller(string $whatsappGroupId): ?Reseller
    {
        return ResellerWhatsAppGroup::query()
            ->where('whatsapp_group_id', $whatsappGroupId)
            ->where('is_active', true)
            ->first()
            ?->reseller;
    }

    /**
     * Bumps the pending row's `last_message_at` on every repeat message
     * from a still-unlinked group — the 24h TTL measures "still no admin
     * action", not "first ever seen". `$preview` is a short, non-PII-
     * sensitive slice of the raw text (admin-linking UX only, never used
     * for anything money/order-related).
     */
    public function capturePending(string $whatsappGroupId, string $preview): void
    {
        ResellerWhatsAppPendingLink::query()->updateOrCreate(
            ['whatsapp_group_id' => $whatsappGroupId],
            ['last_message_preview' => Str::limit($preview, 100), 'last_message_at' => now()],
        );
    }

    /**
     * Links a pending (or any raw) group id to a Reseller — creates the
     * mapping (reactivating it if it already existed but was unlinked),
     * then clears the pending row now that it's served its purpose.
     */
    public function link(Reseller $reseller, string $whatsappGroupId): ResellerWhatsAppGroup
    {
        $group = ResellerWhatsAppGroup::query()->updateOrCreate(
            ['whatsapp_group_id' => $whatsappGroupId],
            ['reseller_id' => $reseller->id, 'is_active' => true],
        );

        ResellerWhatsAppPendingLink::query()->where('whatsapp_group_id', $whatsappGroupId)->delete();

        return $group;
    }

    /** Soft — never deleted, matches `Reseller.is_active`'s own convention. */
    public function unlink(ResellerWhatsAppGroup $group): void
    {
        $group->update(['is_active' => false]);
    }

    public function reactivate(ResellerWhatsAppGroup $group): void
    {
        $group->update(['is_active' => true]);
    }

    public function pruneExpiredPendingLinks(int $ttlHours): int
    {
        return ResellerWhatsAppPendingLink::query()
            ->where('last_message_at', '<=', now()->subHours($ttlHours))
            ->delete();
    }
}
