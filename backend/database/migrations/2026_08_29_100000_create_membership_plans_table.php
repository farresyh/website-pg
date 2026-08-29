<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-027's 2026-08-29 addendum, decisions 14/15/20: exactly two
     * rows, seeded here (not left blank) so the anchor/decoy pricing
     * this table exists to drive (decision 4 of the base ADR) is never
     * a broken/empty state. `fee_sen`/`quota_sen` mirror the base ADR's
     * own draft anchors (decision 8) — still explicitly draft, not a
     * finalized business number. `discount_percent` was never pinned by
     * the base ADR at all (its own breakeven math targeted an aggregate
     * ~2% member markup, not a per-tier discount%) — the values below
     * are placeholders satisfying decision 19's Tier 2 > Tier 1 rule
     * only, awaiting the founder's real number before launch (the
     * platform-wide kill switch, added in the next migration, is what
     * actually keeps this invisible to customers until then).
     */
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('fee_sen');
            $table->unsignedInteger('quota_sen');
            $table->decimal('discount_percent', 5, 2);
            $table->timestamps();
        });

        DB::table('membership_plans')->insert([
            [
                'name' => 'Tier 1',
                'fee_sen' => 890,
                'quota_sen' => 10000,
                'discount_percent' => 50.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Tier 2',
                'fee_sen' => 1990,
                'quota_sen' => 30000,
                'discount_percent' => 80.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
