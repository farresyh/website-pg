<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-074 addendum (2026-09-26): `checkout_idempotency_key`'s bare
     * global-unique column let a key collision match across every
     * reseller — `idempotency_scope` (STORED GENERATED from
     * `IFNULL(wallet_reseller_id, 0)`) plus a composite unique index
     * scopes it per-reseller while every guest/Affiliate-storefront order
     * (`wallet_reseller_id IS NULL`) collapses to scope 0, preserving
     * ADR-041's original global-uniqueness guarantee for that population.
     */
    public function up(): void
    {
        // Guarded, not a bare dropUnique(): some local dev sqlite databases
        // never actually got this index materialized — sqlite's ALTER TABLE
        // can't add a unique constraint to an existing column in one step,
        // so a column added via `->unique()` on sqlite silently ends up
        // column-only, no index, confirmed via `.indexes orders` locally.
        // MySQL prod is unaffected (its ALTER TABLE ADD COLUMN ... UNIQUE
        // is a real single-statement op) but this migration must still run
        // clean against both.
        $hasOldIndex = collect(Schema::getIndexes('orders'))
            ->contains(fn ($index) => $index['name'] === 'orders_checkout_idempotency_key_unique');
        if ($hasOldIndex) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('orders_checkout_idempotency_key_unique');
            });
        }

        // VIRTUAL, not STORED: no `AFTER wallet_reseller_id` placement
        // either — sqlite (the fast test suite's driver, and confirmed
        // against the real local dev DB) refuses ALTER TABLE ADD COLUMN
        // ... AFTER, and separately refuses adding a STORED generated
        // column to any table that already has rows (needs a full table
        // rewrite sqlite's ALTER TABLE can't do). VIRTUAL has neither
        // restriction and both InnoDB and sqlite can still build a real
        // index on it — no storage/read-performance loss for this column.
        // NOT NULL comes AFTER the generated-column clause — MySQL's column
        // grammar is `type [GENERATED ALWAYS] AS (expr) [VIRTUAL|STORED]
        // [NOT NULL]`, the reverse of an ordinary column; sqlite tolerated
        // the other order but MySQL doesn't.
        DB::statement(
            'ALTER TABLE orders ADD COLUMN idempotency_scope INT '.
            'AS (IFNULL(wallet_reseller_id, 0)) VIRTUAL NOT NULL'
        );

        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['idempotency_scope', 'checkout_idempotency_key'], 'orders_idem_scope_key_unique');
        });
    }

    public function down(): void
    {
        // Fail loud, before touching anything: this migration exists
        // specifically to allow two different resellers to share a
        // checkout_idempotency_key. Once that's actually happened, dropping
        // back to a bare global-unique column is not a safe rollback — and
        // checking this first (rather than letting the final statement
        // below throw) avoids leaving the table with idempotency_scope
        // already dropped and no unique constraint restored either way.
        $duplicateCount = DB::table('orders')
            ->select('checkout_idempotency_key')
            ->whereNotNull('checkout_idempotency_key')
            ->groupBy('checkout_idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicateCount > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$duplicateCount} checkout_idempotency_key value(s) are now ".
                'shared across different resellers — restoring the bare global-unique constraint '.
                'would violate real data. Resolve those orders manually before rolling back.'
            );
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_idem_scope_key_unique');
            $table->dropColumn('idempotency_scope');
            $table->unique('checkout_idempotency_key', 'orders_checkout_idempotency_key_unique');
        });
    }
};
