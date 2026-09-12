<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-087 follow-up (2026-09-12, same day as the original build) — the
 * founder wants cost/margin/supplier questions answerable too, not just
 * sales/profit/membership. Adds:
 *
 *  - Cost/markup/supplier columns to `llm_report_orders` — all read
 *    straight off `orders`' own historical snapshot (no time-mismatch
 *    risk: these are the figures that were actually true when that
 *    order was placed).
 *  - A NEW `llm_report_catalog` view — one row per Package, CURRENT
 *    cost/pricing/supplier state, deliberately kept separate from the
 *    per-order fact view: a package's cost can (and does, via the
 *    weekly price-sync job, ADR-067/069) drift from what an old order
 *    actually paid. Mixing "current cost" into the historical orders
 *    view would let the assistant misread an old order's margin against
 *    today's cost. Two views, two time-frames, kept explicit.
 *
 * Dropping and recreating a VIEW touches no data — this is a schema-
 * only operation, not the `migrate:fresh` data-loss hazard AGENTS.md
 * warns about for real tables.
 *
 * Still nothing secret: `suppliers.balance`/`api_config`/
 * `last_test_result` are deliberately left out (operational/credential
 * data, not sales data — decision 3's denylist). Money/cost/markup
 * figures themselves are fine for this `super_admin`-only surface per
 * decision 3's own reasoning (no internal-vs-external boundary crossed
 * here, unlike the reseller/affiliate-facing "platform cost structure
 * is private" rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $paidAtKl = $this->klExpr($driver, 'orders.paid_at');

        DB::statement('DROP VIEW IF EXISTS llm_report_orders');

        DB::statement("
            CREATE VIEW llm_report_orders AS
            SELECT
                orders.id AS order_id,
                orders.order_number,
                {$paidAtKl} AS paid_at_kl,
                orders.customer_email,
                orders.customer_phone,
                orders.game_id,
                games.name AS game_name,
                orders.package_id,
                packages.name AS package_name,
                orders.payment_method,
                orders.pricing_basis,
                orders.delivery_status,
                orders.affiliate_id,
                affiliates.business_name AS affiliate_name,
                orders.wallet_reseller_id,
                resellers.business_name AS reseller_name,
                orders.supplier_id,
                suppliers.name AS supplier_name,
                orders.cost_price,
                orders.transaction_fee,
                orders.voucher_discount,
                orders.affiliate_markup_pct,
                orders.wholesale_markup_pct,
                orders.final_amount,
                orders.normal_selling_price,
                orders.selling_price,
                COALESCE((
                    SELECT SUM(le.amount) FROM ledger_entries le
                    WHERE le.reference_type = 'order' AND le.reference_id = orders.id
                        AND le.type = 'order_profit' AND le.owner_type = 'platform'
                ), 0) AS platform_profit,
                COALESCE((
                    SELECT SUM(le.amount) FROM ledger_entries le
                    WHERE le.reference_type = 'order' AND le.reference_id = orders.id
                        AND le.type = 'order_profit' AND le.owner_type = 'affiliate'
                ), 0) AS affiliate_profit
            FROM orders
            LEFT JOIN games ON games.id = orders.game_id
            LEFT JOIN packages ON packages.id = orders.package_id
            LEFT JOIN affiliates ON affiliates.id = orders.affiliate_id
            LEFT JOIN resellers ON resellers.id = orders.wallet_reseller_id
            LEFT JOIN suppliers ON suppliers.id = orders.supplier_id
            WHERE orders.is_test = 0
                AND orders.payment_status = 'paid'
                AND orders.paid_at IS NOT NULL
        ");

        DB::statement('
            CREATE VIEW llm_report_catalog AS
            SELECT
                packages.id AS package_id,
                packages.name AS package_name,
                packages.denomination,
                packages.is_active,
                packages.sort_order,
                packages.cost_price,
                packages.standard_selling_price,
                packages.markup_percent,
                games.id AS game_id,
                games.name AS game_name,
                games.category,
                suppliers.id AS supplier_id,
                suppliers.name AS supplier_name,
                supplier_products.raw_price,
                supplier_products.raw_currency
            FROM packages
            LEFT JOIN games ON games.id = packages.game_id
            LEFT JOIN suppliers ON suppliers.id = packages.supplier_id
            LEFT JOIN supplier_products
                ON supplier_products.supplier_id = packages.supplier_id
                AND supplier_products.external_ref = packages.supplier_package_ref
        ');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS llm_report_catalog');
        DB::statement('DROP VIEW IF EXISTS llm_report_orders');

        $driver = DB::connection()->getDriverName();
        $paidAtKl = $this->klExpr($driver, 'orders.paid_at');

        // Restores the pre-extension shape from
        // create_llm_report_views.php exactly, so rolling back this
        // migration alone (without also rolling back that one) leaves
        // the assistant working, just narrower again.
        DB::statement("
            CREATE VIEW llm_report_orders AS
            SELECT
                orders.id AS order_id,
                orders.order_number,
                {$paidAtKl} AS paid_at_kl,
                orders.customer_email,
                orders.customer_phone,
                orders.game_id,
                games.name AS game_name,
                orders.package_id,
                packages.name AS package_name,
                orders.payment_method,
                orders.pricing_basis,
                orders.delivery_status,
                orders.affiliate_id,
                affiliates.business_name AS affiliate_name,
                orders.wallet_reseller_id,
                resellers.business_name AS reseller_name,
                orders.final_amount,
                orders.normal_selling_price,
                orders.selling_price,
                COALESCE((
                    SELECT SUM(le.amount) FROM ledger_entries le
                    WHERE le.reference_type = 'order' AND le.reference_id = orders.id
                        AND le.type = 'order_profit' AND le.owner_type = 'platform'
                ), 0) AS platform_profit,
                COALESCE((
                    SELECT SUM(le.amount) FROM ledger_entries le
                    WHERE le.reference_type = 'order' AND le.reference_id = orders.id
                        AND le.type = 'order_profit' AND le.owner_type = 'affiliate'
                ), 0) AS affiliate_profit
            FROM orders
            LEFT JOIN games ON games.id = orders.game_id
            LEFT JOIN packages ON packages.id = orders.package_id
            LEFT JOIN affiliates ON affiliates.id = orders.affiliate_id
            LEFT JOIN resellers ON resellers.id = orders.wallet_reseller_id
            WHERE orders.is_test = 0
                AND orders.payment_status = 'paid'
                AND orders.paid_at IS NOT NULL
        ");
    }

    private function klExpr(string $driver, string $column): string
    {
        return match ($driver) {
            'sqlite' => "datetime({$column}, '+8 hours')",
            default => "CONVERT_TZ({$column}, '+00:00', '+08:00')",
        };
    }
};
