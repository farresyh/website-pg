<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-077 PR-4 (decision 11) — additive index pass on the hot read paths
 * a live Pulse read + two background audits flagged. Every index here
 * backs a query that is otherwise a full scan or a filesort:
 *
 *  - `ledger_entries (type, reference_type, reference_id)` — the table
 *    only carried `(owner_type, owner_id)`. `ReportService::profitTotals`
 *    /`profitByKey`/`exportRows` and `DashboardService::summary` (twice
 *    per dashboard load) all filter `type='order_profit'` +
 *    `reference_type='order'` + `reference_id IN (...)`, scanning the
 *    whole table each call.
 *  - `orders (delivery_status, updated_at)` — `DashboardService`'s
 *    "needs attention" tile runs three `delivery_status = ? AND
 *    updated_at <= ?` counts on every poll.
 *  - `orders.customer_email` — Customer Analytics groups/filters by it;
 *    nothing indexed it.
 *  - `orders (affiliate_id, created_at)` — the affiliate portal order
 *    list (`Affiliate\OrderController::index`) is `WHERE affiliate_id = ?
 *    ORDER BY created_at DESC` (the tenant scope is implicit via
 *    `BelongsToAffiliate`). The single-column `affiliate_id` index (where
 *    one exists as a standalone — the sqlite test DB; see below) can't
 *    serve the sort, so it filesorts. Replaced with the composite.
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
 * `orders.affiliate_id` standalone-index handling mirrors the
 * reseller→affiliate rename migration (2026_09_04_010000): on real MySQL
 * the FK's own auto-created supporting index (`orders_affiliate_id_foreign`)
 * is the only thing covering the column — no separate
 * `orders_affiliate_id_index` was ever created there (verify with
 * `SHOW INDEX FROM orders`). On sqlite (the test DB) FKs are not
 * auto-indexed, so the standalone index does exist. So: detect and drop a
 * genuine standalone `['affiliate_id']` index if present, always add the
 * composite. The MySQL FK-support index stays (dropping an index that
 * backs a live FK constraint is fiddly and buys only a little write speed
 * on a low-write table); the composite supersedes it for query planning
 * since `affiliate_id` is its leftmost column.
 *
 * Additive only — no column changes, no data. Per AGENTS.md's second
 * gotcha this still needs a plain `php artisan migrate` against the local
 * dev DB (never `migrate:fresh`); `php artisan test` passing is not proof
 * the dev DB has these. All auto-generated identifier names checked
 * against MySQL's 64-char limit (longest:
 * `ledger_entries_type_reference_type_reference_id_index`, 53).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->index(['type', 'reference_type', 'reference_id']);
        });

        $ordersAffiliateStandalone = collect(Schema::getIndexes('orders'))
            ->first(fn (array $index) => $index['columns'] === ['affiliate_id']
                && ! $index['unique']
                && $index['name'] !== 'orders_affiliate_id_foreign');

        Schema::table('orders', function (Blueprint $table) use ($ordersAffiliateStandalone) {
            if ($ordersAffiliateStandalone !== null) {
                $table->dropIndex($ordersAffiliateStandalone['name']);
            }

            $table->index(['affiliate_id', 'created_at']);
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

        // Restore the standalone `affiliate_id` index only where nothing
        // else already covers the column exactly (the sqlite case — on
        // MySQL the FK's own index covers it).
        $affiliateColumnCovered = collect(Schema::getIndexes('orders'))
            ->contains(fn (array $index) => $index['columns'] === ['affiliate_id']);

        Schema::table('orders', function (Blueprint $table) use ($affiliateColumnCovered) {
            $table->dropIndex(['affiliate_id', 'created_at']);
            $table->dropIndex(['delivery_status', 'updated_at']);
            $table->dropIndex(['customer_email']);

            if (! $affiliateColumnCovered) {
                $table->index('affiliate_id');
            }
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropIndex(['supplier_id', 'group_label']);
        });

        Schema::table('supplier_request_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
