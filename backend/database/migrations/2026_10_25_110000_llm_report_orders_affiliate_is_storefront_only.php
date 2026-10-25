<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-086 2026-10-25 addendum: one order, one place. A reseller-wallet
 * order carries the primary brand's `affiliate_id` (ADR-073 decision 5),
 * but it is its Reseller's only, so the view gives it no affiliate. The
 * Report Assistant's "sales by affiliate" then matches Reports, and a
 * wallet order is never counted under a brand and a reseller at once.
 * Otherwise the 2026-10-24 view, unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS llm_report_orders');
        DB::statement($this->viewSql(storefrontAffiliateOnly: true));
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS llm_report_orders');
        DB::statement($this->viewSql(storefrontAffiliateOnly: false));
    }

    private function viewSql(bool $storefrontAffiliateOnly): string
    {
        $paidAtKl = match (DB::connection()->getDriverName()) {
            'sqlite' => "datetime(orders.paid_at, '+8 hours')",
            default => "CONVERT_TZ(orders.paid_at, '+00:00', '+08:00')",
        };
        $walletRefund = "COALESCE((
                    SELECT SUM(wr.amount) FROM ledger_entries wr
                    WHERE wr.reference_type = 'order' AND wr.reference_id = orders.id AND wr.type = 'wallet_refund'
                ), 0)";
        $recognisedAtKl = match (DB::connection()->getDriverName()) {
            'sqlite' => "datetime(orders.recognised_at, '+8 hours')",
            default => "CONVERT_TZ(orders.recognised_at, '+00:00', '+08:00')",
        };
        $recognitionColumns = "{$recognisedAtKl} AS recognised_at_kl,
                orders.placed_via,";
        $affiliateColumns = $storefrontAffiliateOnly
            ? 'CASE WHEN orders.wallet_reseller_id IS NULL THEN orders.affiliate_id END AS affiliate_id,
                CASE WHEN orders.wallet_reseller_id IS NULL THEN affiliates.business_name END AS affiliate_name,'
            : 'orders.affiliate_id,
                affiliates.business_name AS affiliate_name,';

        return "
            CREATE VIEW llm_report_orders AS
            SELECT
                orders.id AS order_id,
                orders.order_number,
                {$paidAtKl} AS paid_at_kl,
                {$recognitionColumns}
                orders.customer_email,
                orders.customer_phone,
                orders.game_id,
                games.name AS game_name,
                orders.package_id,
                packages.name AS package_name,
                orders.payment_method,
                orders.pricing_basis,
                orders.delivery_status,
                {$affiliateColumns}
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
                {$walletRefund} AS wallet_refund,
                orders.final_amount - {$walletRefund} AS net_sales,
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
        ";
    }
};
