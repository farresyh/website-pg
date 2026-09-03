<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-068 decision 15 — the /admin/membership/[id] Fee Payments view
 * links each fee record to the ledger entry it booked. The two rows are
 * already created together in one transaction (MembershipFeeService::
 * bookFee); this just records which is which, so the money trail is
 * navigable rather than inferred by ordering. Nullable — the two
 * pre-ADR-068 fee records stay null, harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_fee_records', function (Blueprint $table) {
            $table->foreignId('ledger_entry_id')->nullable()->after('amount_sen')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('membership_fee_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ledger_entry_id');
        });
    }
};
