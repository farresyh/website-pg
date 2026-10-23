<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-126 2026-10-23 addendum, decision 20: a manual order's stale clock,
 * written by its group post (and later by assign and progress), so an
 * unrelated touch of `updated_at` no longer resets it. Null = not yet
 * posted; Order::manualClock() falls back to `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('manual_activity_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('manual_activity_at');
        });
    }
};
