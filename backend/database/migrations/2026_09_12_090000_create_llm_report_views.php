<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-087 decision 1 — the curated, read-only SQL views the LLM Report
 * Assistant is allowed to query. These are the ONLY two tables/views the
 * dedicated `report_assistant` DB connection (see config/database.php)
 * can ever see, and the only two `SqlGuard::ALLOWED_TABLES` will let a
 * generated query reference. No raw table is ever exposed this way.
 *
 * Both views bake in ADR-086's two pinned business rules so the LLM's
 * freely-generated SQL cannot get them wrong regardless of what it
 * writes:
 *  - `llm_report_orders` is one row PER PAID ORDER (never per ledger
 *    row) — `platform_profit`/`affiliate_profit` are correlated
 *    subqueries pre-summed from `ledger_entries` (type=order_profit)
 *    into their own columns, so a `SELECT SUM(final_amount)` alongside
 *    them can never double-count the way a raw JOIN to `ledger_entries`
 *    would (see ReportService's own doc comment for the double-count
 *    hazard this avoids). Scoped to `is_test=false`, `payment_status=
 *    paid`, `paid_at IS NOT NULL` — matches `ReportService::scopedOrders()`
 *    exactly, so the assistant's own WHERE/GROUP BY narrows from an
 *    already-correct base set.
 *  - `paid_at_kl` is pre-converted to Asia/Kuala_Lumpur so the LLM never
 *    has to reason about timezone conversion itself.
 *
 * Decision 3's denylist is structural, not a filter: no credential/
 * secret/password-shaped column from any table is ever selected into
 * either view, and no other table is reachable from them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $paidAtKl = $this->klExpr($driver, 'orders.paid_at');
        $feeCreatedAtKl = $this->klExpr($driver, 'ledger_entries.created_at');

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

        DB::statement("
            CREATE VIEW llm_report_membership_fees AS
            SELECT
                ledger_entries.id AS fee_id,
                {$feeCreatedAtKl} AS created_at_kl,
                ledger_entries.amount,
                memberships.id AS membership_id,
                memberships.affiliate_id,
                affiliates.business_name AS affiliate_name,
                memberships.email AS member_email,
                membership_plans.id AS plan_id,
                membership_plans.name AS plan_name
            FROM ledger_entries
            INNER JOIN memberships ON memberships.id = ledger_entries.reference_id
                AND ledger_entries.reference_type = 'membership'
            LEFT JOIN membership_plans ON membership_plans.id = memberships.membership_plan_id
            LEFT JOIN affiliates ON affiliates.id = memberships.affiliate_id
            WHERE ledger_entries.type = 'membership_fee'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS llm_report_membership_fees');
        DB::statement('DROP VIEW IF EXISTS llm_report_orders');
    }

    /**
     * Same driver-conditional day-bucket discipline as
     * `ReportService::dayBucketExpr()` — `CONVERT_TZ()` doesn't exist in
     * sqlite (the fast test suite), sqlite's `datetime()` modifier
     * syntax doesn't exist in MySQL (production). Full datetime, not
     * just a date, since the assistant may need time-of-day.
     */
    private function klExpr(string $driver, string $column): string
    {
        return match ($driver) {
            'sqlite' => "datetime({$column}, '+8 hours')",
            default => "CONVERT_TZ({$column}, '+00:00', '+08:00')",
        };
    }
};
