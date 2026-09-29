<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-29 audit, Wave 5 Lows — two DB-level backstops on `ledger_entries`,
 * which until now relied on app-level locks alone:
 *
 * 1. `dedupe_key`: a virtual column that is non-null only for entry types
 *    that must happen at most once per (owner, reference). Its unique index
 *    turns a future double-credit bug into a failed insert. Deliberately
 *    excludes `affiliate_tier_fee` and `membership_fee`, which legitimately
 *    repeat against the same subscription/membership every cycle.
 *    `owner_id` is COALESCEd because Platform rows have a NULL owner_id and
 *    NULLs never collide in a unique index. One concatenated column rather
 *    than a 5-column index: three varchar(255) columns in utf8mb4 exceed
 *    InnoDB's 3072-byte key limit.
 * 2. `idempotency_key`: the admin manual wallet credit's double-submit
 *    guard (ResellerWalletService::manualCredit()).
 */
return new class extends Migration
{
    private const ONCE_PER_REFERENCE_TYPES = "'order_profit','wallet_debit','wallet_refund','wallet_topup','voucher_issued'";

    public function up(): void
    {
        $key = DB::connection()->getDriverName() === 'sqlite'
            ? "owner_type || ':' || COALESCE(owner_id, 0) || ':' || type || ':' || COALESCE(reference_type, '') || ':' || reference_id"
            : "CONCAT(owner_type, ':', COALESCE(owner_id, 0), ':', type, ':', COALESCE(reference_type, ''), ':', reference_id)";

        Schema::table('ledger_entries', function (Blueprint $table) use ($key) {
            $table->string('dedupe_key', 191)->nullable()->virtualAs(
                'CASE WHEN type IN ('.self::ONCE_PER_REFERENCE_TYPES.") AND reference_id IS NOT NULL THEN {$key} END",
            );
            $table->string('idempotency_key', 64)->nullable();

            $table->unique('dedupe_key', 'ledger_entries_dedupe_key_unique');
            $table->unique('idempotency_key', 'ledger_entries_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique('ledger_entries_dedupe_key_unique');
            $table->dropUnique('ledger_entries_idempotency_key_unique');
            $table->dropColumn(['dedupe_key', 'idempotency_key']);
        });
    }
};
