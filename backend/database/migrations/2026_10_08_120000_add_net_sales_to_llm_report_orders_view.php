<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ADR-087 2026-10-08 addendum (§16 item 63): `llm_report_orders` gains
     * `wallet_refund` and `net_sales` so the assistant's "sales" is the
     * Reports page's Net Sales (`Order::netSalesSql()`): every paid order,
     * minus any wallet refund. Inlined, not read from `Order`, so this
     * migration stays frozen; `LlmReportViewParityTest` fails if the two
     * drift. Schema-only drop/recreate, same as 2026_09_15_100000.
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS llm_report_orders');
        DB::statement($this->viewSql(withNetSales: true));
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS llm_report_orders');
        DB::statement($this->viewSql(withNetSales: false));
    }

    private function viewSql(bool $withNetSales): string
    {
        $paidAtKl = match (DB::connection()->getDriverName()) {
            'sqlite' => "datetime(orders.paid_at, '+8 hours')",
            default => "CONVERT_TZ(orders.paid_at, '+00:00', '+08:00')",
        };
        $walletRefund = "COALESCE((
                    SELECT SUM(wr.amount) FROM ledger_entries wr
                    WHERE wr.reference_type = 'order' AND wr.reference_id = orders.id AND wr.type = 'wallet_refund'
                ), 0)";
        $netSalesColumns = $withNetSales
            ? "{$walletRefund} AS wallet_refund,
                orders.final_amount - {$walletRefund} AS net_sales,"
            : '';

        return "
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
                {$netSalesColumns}
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
