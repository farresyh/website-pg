<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-094 decision 5, second half: the optional per-combo override
     * ("custom markup or price") — mutually exclusive, enforced at the
     * FormRequest layer (UpdateComboPackageOverrideRequest), same
     * `prohibits`-each-other idiom as `denomination`/`catalog_code`.
     * Both plain additive nullable columns — no existing row is
     * touched, no `->change()` needed, so none of the sibling combo-
     * support migration's view-drop workaround applies here.
     *
     * Meaningless (left null) for a non-combo Package. When neither is
     * set, `ComboPricingService::recompute()` falls back to decision
     * 5's default: the literal sum of components' own selling prices.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->decimal('combo_override_markup_percent', 5, 2)->nullable()->after('is_combo');
            $table->unsignedInteger('combo_override_price')->nullable()->after('combo_override_markup_percent');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['combo_override_markup_percent', 'combo_override_price']);
        });
    }
};
