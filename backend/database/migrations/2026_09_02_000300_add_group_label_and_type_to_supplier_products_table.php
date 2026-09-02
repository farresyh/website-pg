<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-067 decisions 4 & 5.
     *
     * `group_label` is the string the Product Manager groups a
     * supplier's raw catalog by — set by the adapter itself
     * (`SupplierCatalogItem::$groupLabel`), because only the adapter
     * knows which of its supplier's fields carries the "which game"
     * identity: Gamevion's `category_raw` is already edition-level;
     * Digiflazz's `category` is a flat "Games" for every game, so its
     * adapter passes `brand` instead. Everything downstream (the
     * categories endpoint, the link contract, the UI) groups by
     * `(supplier_id, group_label)` uniformly with no supplier-specific
     * branching.
     *
     * Added NOT NULL with a '' default (so existing rows fill in
     * without a separate nullable→change step — mirrors the
     * `category_raw ?? ''` fallback the grouping code already used),
     * then backfilled `group_label = category_raw` for every existing
     * row (all Gamevion today) so the existing linked + promoted
     * "Mobile Legends: Bang Bang (Malaysia)" group survives the switch.
     *
     * `type` is the supplier's own sub-classification (Digiflazz
     * membership tiers, etc.) — carried raw for a future checkout need,
     * never used for grouping. Null for every existing row and for all
     * Gamevion rows (no `type` concept there).
     *
     * Additive-migrate gotcha (AGENTS.md): run plain `php artisan
     * migrate` against the local dev DB too — the test suites never
     * touch it. Never `migrate:fresh`.
     */
    public function up(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->string('group_label')->default('')->after('category_raw');
            $table->string('type')->nullable()->after('group_label');
        });

        DB::table('supplier_products')->update([
            'group_label' => DB::raw("COALESCE(category_raw, '')"),
        ]);
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropColumn(['group_label', 'type']);
        });
    }
};
