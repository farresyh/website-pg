<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-104 2026-10-08 addendum R16. NOT NULL with no default: every
     * writer names it (OrderDraft makes it a required argument).
     *
     * Backfill: a wallet order whose idempotency key starts `wa:` is the
     * Bot's (`ResellerBotService` builds `wa:{group}:{message}`; all 7 on
     * prod); any other wallet order is the API's (0 on prod). A sandbox
     * order is `is_test` with `payment_method = 'sandbox'`
     * (`SandboxOrderController`). Everything else is the storefront.
     * Inferring from the prefix is safe only here, once, over history: an
     * API client may send any key from now on.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('placed_via', 20)->nullable()->after('wallet_reseller_id');
        });

        DB::table('orders')->whereNotNull('wallet_reseller_id')->where('checkout_idempotency_key', 'like', 'wa:%')->update(['placed_via' => 'reseller_bot']);
        DB::table('orders')->whereNotNull('wallet_reseller_id')->whereNull('placed_via')->update(['placed_via' => 'reseller_api']);
        DB::table('orders')->where('is_test', true)->where('payment_method', 'sandbox')->whereNull('placed_via')->update(['placed_via' => 'sandbox']);
        DB::table('orders')->whereNull('placed_via')->update(['placed_via' => 'storefront']);

        // sqlite's `->change()` rebuilds the table and refuses to rename it
        // under a view that reads it (the llm_report_* views). Re-create
        // them from their own stored SQL rather than a fourth copy of it.
        // MySQL alters in place.
        $views = DB::getDriverName() === 'sqlite'
            ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'view'")
            : [];

        foreach ($views as $view) {
            DB::statement("DROP VIEW \"{$view->name}\"");
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('placed_via', 20)->nullable(false)->change();
        });

        foreach ($views as $view) {
            DB::statement($view->sql);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('placed_via');
        });
    }
};
