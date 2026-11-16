<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-058 2026-11-16 addendum: one append-only row per change of an
 * affiliate's payout bank details, written only by
 * AffiliateBankDetailsService. A monthly payout ticket copies the details
 * when it opens, so a change made before the 1st is paid out; this row says
 * who made it (the portal login, or an admin impersonating it) and what it
 * replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_bank_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->index()->constrained();
            $table->foreignId('affiliate_user_id')->nullable()->index()->constrained();
            $table->foreignId('impersonation_session_id')->nullable()->index()->constrained('affiliate_impersonation_sessions');
            $table->string('old_bank_name')->nullable();
            $table->string('old_bank_account_no', 100)->nullable();
            $table->string('old_bank_account_holder')->nullable();
            $table->string('new_bank_name');
            $table->string('new_bank_account_no', 100);
            $table->string('new_bank_account_holder');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_bank_changes');
    }
};
