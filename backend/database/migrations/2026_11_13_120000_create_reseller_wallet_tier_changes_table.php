<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-139 decision 7: every wallet tier a reseller has had, and the 1st (KL)
 * a pending change takes effect. The tier of a month is the latest row
 * effective on or before that month's 1st. Existing resellers are
 * backfilled with their current tier, effective on the day they were made
 * (KL), already applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_wallet_tier_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->restrictOnDelete();
            $table->foreignId('from_tier_id')->nullable()->constrained('reseller_tiers')->restrictOnDelete();
            $table->foreignId('to_tier_id')->constrained('reseller_tiers')->restrictOnDelete();
            $table->date('effective_on');
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['reseller_id', 'effective_on']);
            $table->index(['applied_at', 'effective_on']);
        });

        $now = now();
        DB::table('resellers')->whereNotNull('reseller_tier_id')->orderBy('id')->get(['id', 'reseller_tier_id', 'created_at'])
            ->each(fn ($r) => DB::table('reseller_wallet_tier_changes')->insert([
                'reseller_id' => $r->id,
                'from_tier_id' => null,
                'to_tier_id' => $r->reseller_tier_id,
                'effective_on' => Carbon::parse($r->created_at ?? $now, 'UTC')->setTimezone('Asia/Kuala_Lumpur')->toDateString(),
                'applied_at' => $now,
                'admin_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_wallet_tier_changes');
    }
};
