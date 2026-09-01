<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-027 Phase 6. Additive, standard-default (per the base ADR's own
 * addendum consequence-to-track): every existing order implicitly stays
 * `pricing_basis = standard`, `member_discount_percent`/
 * `normal_selling_price`/`membership_id` all null — only a member-priced
 * checkout (Phase 6) ever populates them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('pricing_basis')->default('standard')->after('reseller_id');
            $table->foreignId('membership_id')->nullable()->after('pricing_basis')->constrained('memberships')->nullOnDelete();
            $table->decimal('member_discount_percent', 5, 2)->nullable()->after('membership_id');
            $table->unsignedInteger('normal_selling_price')->nullable()->after('member_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membership_id');
            $table->dropColumn(['pricing_basis', 'member_discount_percent', 'normal_selling_price']);
        });
    }
};
