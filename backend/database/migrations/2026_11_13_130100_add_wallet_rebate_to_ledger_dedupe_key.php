<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-139 decision 11: `wallet_rebate` joins the once-per-reference backstop
 * (2026_09_29_230000), so one milestone row can never be credited twice even
 * if the claim's lock were bypassed. The key is a virtual column, so it is
 * dropped and re-added with the longer type list; no data moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild("'order_profit','wallet_debit','wallet_refund','wallet_topup','voucher_issued','wallet_rebate'");
    }

    public function down(): void
    {
        $this->rebuild("'order_profit','wallet_debit','wallet_refund','wallet_topup','voucher_issued'");
    }

    private function rebuild(string $types): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique('ledger_entries_dedupe_key_unique');
        });
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn('dedupe_key');
        });

        $key = DB::connection()->getDriverName() === 'sqlite'
            ? "owner_type || ':' || COALESCE(owner_id, 0) || ':' || type || ':' || COALESCE(reference_type, '') || ':' || reference_id"
            : "CONCAT(owner_type, ':', COALESCE(owner_id, 0), ':', type, ':', COALESCE(reference_type, ''), ':', reference_id)";

        Schema::table('ledger_entries', function (Blueprint $table) use ($key, $types) {
            $table->string('dedupe_key', 191)->nullable()->virtualAs(
                "CASE WHEN type IN ({$types}) AND reference_id IS NOT NULL AND reason IS NULL THEN {$key} END",
            );
            $table->unique('dedupe_key', 'ledger_entries_dedupe_key_unique');
        });
    }
};
