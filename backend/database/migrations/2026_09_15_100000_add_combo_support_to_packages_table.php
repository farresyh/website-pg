<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-094 decisions 1/3: a combo Package is assembled from several
     * existing Packages (`package_components`, next migration) instead
     * of tracing to one real supplier item — the one deliberate
     * exception to PackageController's own doc comment ("every Package
     * must trace back to a real supplier item"). `is_combo` is the
     * fulfillment-engine signal (`OrderFulfillmentService`'s combo
     * branch); `supplier_id`/`supplier_package_ref` become nullable
     * specifically for `is_combo=true` rows, mutually-exclusive with
     * having components — enforced at the FormRequest layer
     * (StoreComboPackageRequest), not the DB, mirroring the existing
     * `denomination`/`catalog_code` `prohibits`-each-other pattern.
     *
     * The existing `unique(supplier_id, supplier_package_ref)` index
     * (migration 2026_07_25_180000) still holds with both nullable —
     * MySQL treats NULL as distinct in a unique index, so any number
     * of combo rows (both columns null) coexist without collision.
     *
     * `->change()` on sqlite rebuilds the table (copy-to-temp,
     * drop, rename) — ADR-087's 2026-09-12 follow-up migration left
     * `llm_report_catalog`/`llm_report_orders` VIEWs depending on
     * `packages`, and sqlite refuses to rename a table out from under
     * a view that reads it. Drop both views first, recreate them
     * (identical SQL to that migration's own `up()`) after — schema-
     * only, same "touches no data" reasoning that migration's own
     * comment already established for view drop/recreate. MySQL has no
     * such restriction, but doing this unconditionally keeps behaviour
     * identical across drivers rather than branching on `getDriverName()`.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('is_combo')->default(false)->after('catalog_code');
        });

        $this->dropDependentViews();

        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->change();
            $table->string('supplier_package_ref')->nullable()->change();
        });

        $this->recreateDependentViews();
    }

    public function down(): void
    {
        $this->dropDependentViews();

        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable(false)->change();
            $table->string('supplier_package_ref')->nullable(false)->change();
        });

        $this->recreateDependentViews();

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('is_combo');
        });
    }

    private function dropDependentViews(): void
    {
        DB::statement('DROP VIEW IF EXISTS llm_report_catalog');
        DB::statement('DROP VIEW IF EXISTS llm_report_orders');
    }

    /**
     * Identical to `2026_09_12_100000_extend_llm_report_orders_and_add_catalog_view`'s
     * own `up()` — duplicated rather than called directly (migration
     * classes are anonymous, not addressable), same duplication that
     * migration's own `down()` already accepts for the pre-extension
     * shape.
     */
    private function recreateDependentViews(): void
    {
        $driver = DB::connection()->getDriverName();
        $paidAtKl = match ($driver) {
            'sqlite' => "datetime(orders.paid_at, '+8 hours')",
            default => "CONVERT_TZ(orders.paid_at, '+00:00', '+08:00')",
        };

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
};
