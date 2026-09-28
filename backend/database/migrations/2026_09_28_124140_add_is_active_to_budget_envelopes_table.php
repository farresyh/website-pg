<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum, real gap found the day
 * after launch (founder question — no way to rename or retire a
 * mistakenly-created envelope). Rename is a free-standing `name` update
 * (safe — doesn't touch any entry's own recorded fields). "Delete" is
 * deliberately never offered: `budget_envelope_entries.budget_envelope_id`
 * is `restrictOnDelete()`, so a real, used envelope can never be hard-
 * deleted anyway — `is_active` (soft-archive, same convention
 * `Reseller`/`Affiliate` already use) hides a mistaken/retired envelope
 * from the active list while its full entry history stays visible/
 * exportable forever, consistent with this whole ADR family's "never
 * destroy a financial record" principle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_envelopes', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('budget_envelopes', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
