<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum — the discretionary,
 * director-controlled half of the money the founder wants captured
 * (capital/OPEX/marketing/dividends), deliberately separate from the
 * operational Transaction Register: money already accounted for as an
 * expense in the Monthly Summary (e.g. affiliate commission) never also
 * comes out of one of these envelopes, or it would be double-counted.
 *
 * Four starter envelopes seeded here (not left blank) matching the
 * founder's own real, confirmed-in-progress numbers — Capital Rolling
 * and Marketing Budget amounts are committed (Lokman), Maintenance is
 * not yet confirmed. New envelopes can be added later via the admin
 * screen; this table is not a fixed enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_envelopes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        DB::table('budget_envelopes')->insert([
            ['name' => 'Capital Rolling', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Marketing Budget', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Maintenance / Operations', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Company Savings', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_envelopes');
    }
};
