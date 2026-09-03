<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-069 decision 10 — the supplier's pre-conversion price + its
     * currency, kept alongside the converted `price_sen` purely so a
     * "Rp 20,000 = RM 4.56" sanity line can be shown in the Product
     * Manager. Set by each adapter from its own catalog shape
     * (`SupplierCatalogItem::$rawPrice` / `$rawCurrency` — Digiflazz
     * `price` + 'IDR', Gamevion its own value + 'MYR'); written by
     * `ProductSyncService` with no per-supplier branching.
     *
     * Both nullable, no index — display-only, never queried or
     * filtered. Forward-only: populated on each row's next sync, no
     * backfill (the scheduled sync runs often; a one-off backfill
     * script would add nothing lasting). FX conversion itself is
     * unchanged — ADR-033's rate handling is not reopened.
     *
     * Additive-migrate gotcha (AGENTS.md): run plain `php artisan
     * migrate` against the local dev DB too. Never `migrate:fresh`.
     */
    public function up(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->decimal('raw_price', 15, 2)->nullable()->after('price_sen');
            $table->string('raw_currency', 3)->nullable()->after('raw_price');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropColumn(['raw_price', 'raw_currency']);
        });
    }
};
