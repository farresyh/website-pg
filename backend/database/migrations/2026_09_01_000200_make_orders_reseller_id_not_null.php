<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-057 decision 2's deferred follow-up, now unblocked (ADR-061 PR-B):
 * `orders.reseller_id` becomes NOT NULL. The backfill itself already ran
 * in 2026_08_30_110000 (NULL → platform owner); this tightens the column
 * and swaps the FK's delete rule.
 *
 * The original FK (2026_07_25_150100) was `nullOnDelete()` — which MySQL
 * refuses to combine with a NOT NULL column (SET NULL would violate it).
 * ADR-061 also makes the old rationale moot: every order now belongs to a
 * real brand, and a `Reseller` is soft-deleted, never hard-deleted (the
 * primary can't be deleted at all; a non-primary delete is guarded on a
 * zero balance). `restrictOnDelete()` is the correct rule now — an order
 * keeps its brand for the life of the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('orders')->whereNull('reseller_id')->exists()) {
            $primaryId = DB::table('resellers')->where('is_primary', true)->value('id')
                ?? DB::table('resellers')->where('business_name', 'Platform Owner')->value('id');

            DB::table('orders')->whereNull('reseller_id')->update(['reseller_id' => $primaryId]);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('reseller_id')->nullable(false)->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('reseller_id')->references('id')->on('resellers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('reseller_id')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('reseller_id')->references('id')->on('resellers')->nullOnDelete();
        });
    }
};
