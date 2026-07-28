<?php

namespace App\Services\Fraud;

use App\Models\BlacklistEntry;
use App\Models\BlacklistHit;

/**
 * ADR-007 / FRAUD-1..2: independent of whatever blacklist (if any) a
 * given supplier offers - checked at order creation, before payment
 * or supplier submission, per prd.md §7.1 step 5.
 */
final class BlacklistService
{
    /**
     * Matches any active entry whose type/value pair equals one of
     * the given, non-null values. Returns the first match - which
     * specific entry matched only matters for the audit trail
     * (recordHit()), not for the checkout-blocking decision itself.
     */
    public function check(?string $playerId, ?string $email, ?string $phone): ?BlacklistEntry
    {
        if ($playerId === null && $email === null && $phone === null) {
            return null;
        }

        return BlacklistEntry::query()
            ->where('is_active', true)
            ->where(function ($query) use ($playerId, $email, $phone) {
                if ($playerId !== null) {
                    $query->orWhere(fn ($q) => $q->where('type', BlacklistEntryType::PlayerId)->where('value', $playerId));
                }

                if ($email !== null) {
                    $query->orWhere(fn ($q) => $q->where('type', BlacklistEntryType::Email)->where('value', $email));
                }

                if ($phone !== null) {
                    $query->orWhere(fn ($q) => $q->where('type', BlacklistEntryType::Phone)->where('value', $phone));
                }
            })
            ->first();
    }

    /**
     * FRAUD-3's "view history of orders blocked by a given entry" -
     * records what was actually submitted, not just which field
     * matched, since a real attempt carries all three fields even
     * though only one may have triggered the match.
     */
    public function recordHit(BlacklistEntry $entry, ?string $playerId, ?string $email, ?string $phone, ?string $ip): BlacklistHit
    {
        return $entry->hits()->create([
            'player_id' => $playerId,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'ip' => $ip,
        ]);
    }
}
