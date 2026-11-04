<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-118 2026-11-04 addendum (V1–V20), PR 1: KOL campaigns, their promo
 * codes and each code's redemptions, plus the order snapshot columns.
 * The fee schedule (V12) arrives with the accounting PR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            // V1: validated as the primary brand in v1; kept so a third-party brand needs no backfill.
            $table->foreignId('affiliate_id')->constrained();
            $table->string('name', 120);
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index('affiliate_id');
            $table->index('created_by');
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            // V17: stored uppercase, unique forever (ended codes included).
            $table->string('code', 20)->unique();
            $table->decimal('discount_percent', 5, 2);
            $table->unsignedInteger('max_discount_sen')->nullable();
            $table->unsignedInteger('budget_sen');
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index('campaign_id');
        });

        Schema::create('promo_code_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('amount_sen');
            $table->unsignedInteger('restored_sen')->default(0);
            $table->string('status', 10);
            $table->timestamp('restored_at')->nullable();
            // V9: a Paid-after-Failed delivery re-reserved past the budget.
            $table->boolean('over_cap')->default(false);
            $table->timestamps();

            $table->index(['promo_code_id', 'status']);
        });

        // sqlite rebuilds `orders` to add a foreign key and refuses to rename
        // it under a view that reads it (the llm_report_* views): re-create
        // them from their own stored SQL, as 2026_10_08_140000 does. MySQL
        // alters in place.
        $views = self::sqliteViews();

        foreach ($views as $view) {
            DB::statement("DROP VIEW \"{$view->name}\"");
        }

        Schema::table('orders', function (Blueprint $table) {
            // The code redeemed on this order (R1: selling_price is already after it).
            $table->foreignId('promo_code_id')->nullable()->after('voucher_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('promo_discount_sen')->default(0)->after('voucher_discount');
            // V6: the KOL code this order counts for (redeemed here or earlier); never rewritten.
            $table->foreignId('attributed_promo_code_id')->nullable()->after('promo_code_id')->constrained('promo_codes')->nullOnDelete();

            $table->index('promo_code_id');
            $table->index('attributed_promo_code_id');
        });

        foreach ($views as $view) {
            DB::statement($view->sql);
        }

        Schema::table('platform_settings', function (Blueprint $table) {
            // V10/V19: the post-promo platform margin a code may not go below.
            $table->decimal('campaign_min_margin_percent', 5, 2)->default(2.00);
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('campaign_min_margin_percent');
        });

        $views = self::sqliteViews();

        foreach ($views as $view) {
            DB::statement("DROP VIEW \"{$view->name}\"");
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attributed_promo_code_id');
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropColumn('promo_discount_sen');
        });

        foreach ($views as $view) {
            DB::statement($view->sql);
        }

        Schema::dropIfExists('promo_code_redemptions');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('campaigns');
    }

    /** @return list<object{name: string, sql: string}> */
    private static function sqliteViews(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'view'")
            : [];
    }
};
