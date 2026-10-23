<?php

namespace App\Services\WhatsApp;

use App\Models\Reseller;
use App\Models\Supplier;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppPendingLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-075 decision 2, generalised by ADR-125 decision 11: the one place a
 * bot-session WhatsApp group is resolved, captured as pending, linked and
 * unlinked, for a reseller or a manual supplier.
 *
 * Ownership rules (ADR-125 decisions 55-56): a group never moves to another
 * owner, a supplier has one active group, and an active manual supplier
 * never loses its last one. A supplier group receives customer logins, so
 * none of this is left to a caller.
 */
final class WhatsAppGroupService
{
    public function activeGroup(string $whatsappGroupId): ?WhatsAppGroup
    {
        return WhatsAppGroup::query()
            ->where('whatsapp_group_id', $whatsappGroupId)
            ->where('is_active', true)
            ->first();
    }

    public function resolveReseller(string $whatsappGroupId): ?Reseller
    {
        $group = $this->activeGroup($whatsappGroupId);

        return $group?->owner_type === WhatsAppGroup::OWNER_RESELLER ? $group->reseller : null;
    }

    public function activeGroupFor(Supplier $supplier): ?WhatsAppGroup
    {
        return WhatsAppGroup::query()
            ->where('owner_type', WhatsAppGroup::OWNER_SUPPLIER)
            ->where('owner_id', $supplier->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * An unrecognized group gets recorded so it can be linked — same
     * "send a test message" onboarding as ADR-075. Bumps the preview +
     * timestamp on repeat messages rather than duplicating rows.
     */
    public function capturePending(string $whatsappGroupId, string $preview): void
    {
        WhatsAppPendingLink::query()->updateOrCreate(
            ['whatsapp_group_id' => $whatsappGroupId],
            ['last_message_preview' => Str::limit($preview, 100), 'last_message_at' => now()],
        );
    }

    public function linkReseller(Reseller $reseller, string $whatsappGroupId): WhatsAppGroup
    {
        return $this->link(WhatsAppGroup::OWNER_RESELLER, $reseller->id, $whatsappGroupId);
    }

    public function linkSupplier(Supplier $supplier, string $whatsappGroupId): WhatsAppGroup
    {
        if ($supplier->isApiBacked()) {
            throw ValidationException::withMessages(['supplier' => ['Only a manual supplier has a WhatsApp group.']]);
        }

        if ($supplier->isAssignmentPool()) {
            throw ValidationException::withMessages(['supplier' => ['The joki pool posts to no group; each penjoki has their own.']]);
        }

        return $this->link(WhatsAppGroup::OWNER_SUPPLIER, $supplier->id, $whatsappGroupId);
    }

    public function unlink(WhatsAppGroup $group): void
    {
        DB::transaction(function () use ($group) {
            $locked = WhatsAppGroup::query()->lockForUpdate()->findOrFail($group->id);

            if ($locked->owner_type === WhatsAppGroup::OWNER_SUPPLIER && $locked->is_active && $locked->supplier?->is_active) {
                throw ValidationException::withMessages(['whatsapp_group_id' => ['Deactivate this supplier before unlinking its WhatsApp group.']]);
            }

            $locked->update(['is_active' => false]);
        });
    }

    public function reactivate(WhatsAppGroup $group): void
    {
        DB::transaction(function () use ($group) {
            $locked = WhatsAppGroup::query()->lockForUpdate()->findOrFail($group->id);
            $this->assertNoOtherActiveSupplierGroup($locked->owner_type, $locked->owner_id, $locked->id);
            $locked->update(['is_active' => true]);
        });
    }

    /** Decision 56: called by every path that switches a supplier on. */
    public function assertSupplierMayBeActive(Supplier $supplier): void
    {
        if (! $supplier->isApiBacked() && ! $supplier->isAssignmentPool() && $this->activeGroupFor($supplier) === null) {
            throw ValidationException::withMessages(['is_active' => ['Link this supplier\'s WhatsApp group before activating it.']]);
        }
    }

    public function pruneExpiredPendingLinks(int $ttlHours): int
    {
        return WhatsAppPendingLink::query()
            ->where('last_message_at', '<=', now()->subHours($ttlHours))
            ->delete();
    }

    private function link(string $ownerType, int $ownerId, string $whatsappGroupId): WhatsAppGroup
    {
        return DB::transaction(function () use ($ownerType, $ownerId, $whatsappGroupId) {
            $group = WhatsAppGroup::query()->where('whatsapp_group_id', $whatsappGroupId)->lockForUpdate()->first();

            if ($group !== null && ! $group->isOwnedBy($ownerType, $ownerId)) {
                throw ValidationException::withMessages(['whatsapp_group_id' => ['This WhatsApp group is already linked to another account. Unlink and remove it there first.']]);
            }

            $this->assertNoOtherActiveSupplierGroup($ownerType, $ownerId, $group?->id);

            $group ??= new WhatsAppGroup(['whatsapp_group_id' => $whatsappGroupId, 'owner_type' => $ownerType, 'owner_id' => $ownerId]);
            $group->is_active = true;
            $group->save();

            WhatsAppPendingLink::query()->where('whatsapp_group_id', $whatsappGroupId)->delete();

            return $group;
        });
    }

    private function assertNoOtherActiveSupplierGroup(string $ownerType, int $ownerId, ?int $exceptGroupId): void
    {
        if ($ownerType !== WhatsAppGroup::OWNER_SUPPLIER) {
            return;
        }

        $other = WhatsAppGroup::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('is_active', true)
            ->when($exceptGroupId !== null, fn ($q) => $q->whereKeyNot($exceptGroupId))
            ->exists();

        if ($other) {
            throw ValidationException::withMessages(['whatsapp_group_id' => ['This supplier already has an active WhatsApp group. Unlink it first.']]);
        }
    }
}
