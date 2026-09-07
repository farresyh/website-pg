<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-077 PR-4 (decision 11) — additive index pass on the hot read paths
 * a live Pulse read + two background audits flagged. Every index here
 * backs a query that is otherwise a full scan:
 *
 *  - `ledger_entries (type, reference_type, reference_id)` — the table
 *    only carried `(owner_type, owner_id)`. `ReportService::profitTotals`
 *    /`profitByKey`/`exportRows` and `DashboardService::summary` (twice
 *    per dashboard load) all filter `type='order_profit'` +
 *    `reference_type='order'` + `reference_id IN (...)`, scanning the
 *    whole table each call. This is the acute one Pulse flagged.
 *  - `orders (delivery_status, updated_at)` — `DashboardService`'s
 *    "needs attention" tile runs three `delivery_status = ? AND
 *    updated_at <= ?` counts on every poll.
 *  - `orders.customer_email` — Customer Analytics groups/filters by it;
 *    nothing indexed it.
 *  - `supplier_products (supplier_id, group_label)` — the Product
 *    Manager's supplier-scoped grouping/link contract (ADR-067) is keyed
 *    on exactly this pair; only `(supplier_id, external_ref)` unique and
 *    the FK index existed.
 *  - `supplier_request_logs.created_at` — the retention prune
 *    (`PruneSupplierRequestLogsCommand`) does `call_type != 'validatePlayer'
 *    AND created_at <= ?`; the `!=` on the leftmost column of the existing
 *    `(call_type, created_at)` composite defeats an efficient seek, so a
 *    standalone `created_at` lets the planner range-scan on age first.
 *
 * ADR-077 decision 11 also listed `orders (affiliate_id, created_at)` to
 * replace the single-column `affiliate_id` index (the affiliate portal
 * order list, `WHERE affiliate_id = ? ORDER BY created_at DESC`,
 * filesorts). It is deliberately NOT in this migration: on MySQL the only
 * index covering `affiliate_id` is the one supporting the FK constraint,
 * and adding a `(affiliate_id, created_at)` composite makes InnoDB drop
 * that auto-created FK index as redundant — leaving the composite itself
 * as the FK's sole support, so the migration's own `down()` (and every
 * `DatabaseMigrations` rollback in the concurrency suite) then fails with
 * "Cannot drop index ... needed in a foreign key constraint". Doing it
 * safely means dropping and re-adding the FK on the `orders` table — a
 * full row re-validation lock on the money table for a filesort that only
 * bites once an affiliate has thousands of orders (none do today). It is
 * split out to its own carefully-planned change, folded into the Reports
 * restructure ADR which already owns the affiliate reporting dimension.
 *
 * Additive only — no column changes, no data, nothing dropped. Per
 * AGENTS.md's second gotcha this still needs a plain `php artisan migrate`
 * against the local dev DB (never `migrate:fresh`); `php artisan test`
 * passing is not proof the dev DB has these. All auto-generated
 * identifier names checked against MySQL's 64-char limit (longest:
 * `ledger_entries_type_reference_type_reference_id_index`, 53).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->index(['type', 'reference_type', 'reference_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['delivery_status', 'updated_at']);
            $table->index('customer_email');
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->index(['supplier_id', 'group_label']);
        });

        Schema::table('supplier_request_logs', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['type', 'reference_type', 'reference_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['delivery_status', 'updated_at']);
            $table->dropIndex(['customer_email']);
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropIndex(['supplier_id', 'group_label']);
        });

        Schema::table('supplier_request_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
