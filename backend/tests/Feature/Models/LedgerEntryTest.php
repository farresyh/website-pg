<?php

namespace Tests\Feature\Models;

use App\Models\LedgerEntry;
use App\Services\Ledger\LedgerOwnerType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-083 2026-10-10 addendum, decision 16: the month-close cash equation
 * reads ledger balances as of a past date, so append-only is enforced at
 * the model layer, the same as `SupplierLedgerEntry`.
 */
class LedgerEntryTest extends TestCase
{
    use RefreshDatabase;

    private function entry(): LedgerEntry
    {
        return LedgerEntry::query()->create([
            'owner_type' => LedgerOwnerType::Platform->value,
            'owner_id' => null,
            'type' => 'membership_fee',
            'amount' => 2500,
        ]);
    }

    public function test_updating_a_persisted_row_throws(): void
    {
        $entry = $this->entry();

        $this->expectException(\LogicException::class);

        $entry->amount = 9999;
        $entry->save();
    }

    public function test_deleting_a_persisted_row_throws(): void
    {
        $entry = $this->entry();

        $this->expectException(\LogicException::class);

        $entry->delete();
    }
}
