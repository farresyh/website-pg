<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-126 PR 2b (2026-10-23 addendum, decisions 1, 3-8, 12-14): the joki
 * catalog. Every price is whole ringgit (decision 4) and lives only in
 * these tables (F1); the joki game's `Package` rows exist so an order has
 * a package.
 *
 *  - `joki_settings`: one row — the global switch, payout share, minimum
 *    stars, the Mythic Grading flat price and the star it guarantees,
 *    the Express wording and the terms.
 *  - `joki_tiers`: the rank model, bottom first. A divisional tier
 *    (Warrior … Legend) has divisions × stars; a Mythic tier starts at a
 *    Mythic star count (Mythic 0, Honor 25, Glory 50, Immortal 100).
 *  - `joki_routes`: a route package's from/to rank and its promo price, or
 *    a quotation (no price; the page opens WhatsApp).
 *  - `joki_orders`: what each joki order bought, with a snapshot of the
 *    model, rates, share and terms it was priced with.
 *  - `affiliates.joki_enabled`: per brand, owned brands only.
 *
 * Seeded here: the settings row, the 2026 rank model and the ADR's draft
 * rates — joki-only tables, read by nothing else. The joki game and its
 * calculator package are created by JokiCatalog::ensure() when joki is
 * set up, so no other catalog, count or test sees them before then. Joki
 * stays off until the founder confirms the rates with the penjoki.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('joki_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('payout_share_pct', 5, 2)->default(100);
            $table->unsignedSmallInteger('min_stars')->default(5);
            $table->unsignedInteger('grading_price_rm');
            $table->unsignedInteger('grading_express_price_rm');
            $table->unsignedSmallInteger('grading_lands_at_stars')->default(15);
            $table->unsignedSmallInteger('max_mythic_stars')->default(1000);
            $table->json('roles');
            $table->string('express_description', 500)->nullable();
            $table->text('terms_html')->nullable();
            $table->timestamps();
        });

        Schema::create('joki_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->unsignedSmallInteger('sort_order');
            $table->string('kind', 16); // divisional | mythic
            $table->unsignedTinyInteger('divisions')->nullable();
            $table->unsignedTinyInteger('stars_per_division')->nullable();
            $table->unsignedSmallInteger('mythic_from_stars')->nullable();
            $table->unsignedInteger('rate_rm');
            $table->unsignedInteger('express_rate_rm');
            $table->string('icon_url', 2048)->nullable();
            $table->timestamps();
            $table->unique('sort_order');
        });

        Schema::create('joki_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('from_tier_id')->constrained('joki_tiers');
            $table->unsignedTinyInteger('from_division')->nullable();
            $table->unsignedSmallInteger('from_stars')->nullable(); // null = Mythic, not yet graded
            $table->foreignId('to_tier_id')->constrained('joki_tiers');
            $table->unsignedTinyInteger('to_division')->nullable();
            $table->unsignedSmallInteger('to_stars');
            $table->unsignedInteger('price_rm')->nullable();
            $table->boolean('is_quotation')->default(false);
            $table->timestamps();
        });

        Schema::create('joki_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('from_rank');
            $table->json('to_rank');
            $table->boolean('is_express')->default(false);
            $table->string('hero_request', 255)->nullable();
            $table->string('role', 50)->nullable();
            $table->string('play_window', 255)->nullable();
            $table->json('quote');
            $table->decimal('payout_share_pct', 5, 2);
            $table->text('terms_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::table('affiliates', function (Blueprint $table) {
            $table->boolean('joki_enabled')->default(false)->after('membership_enabled');
        });

        $now = now();

        DB::table('joki_settings')->insert([
            'is_enabled' => false, 'payout_share_pct' => 100, 'min_stars' => 5,
            'grading_price_rm' => 39, 'grading_express_price_rm' => 39, 'grading_lands_at_stars' => 15,
            'max_mythic_stars' => 1000, 'roles' => json_encode(['Gold Lane', 'EXP Lane', 'Mid Lane', 'Jungler', 'Roam']),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // The 2026 ladder; ADR-126 addendum decision 4's draft rates (Express = Reguler + RM1).
        $tiers = [
            ['Warrior', 'divisional', 3, 3, null, 2],
            ['Elite', 'divisional', 4, 4, null, 2],
            ['Master', 'divisional', 4, 4, null, 2],
            ['Grandmaster', 'divisional', 5, 5, null, 2],
            ['Epic', 'divisional', 5, 5, null, 2],
            ['Legend', 'divisional', 5, 5, null, 2],
            ['Mythic', 'mythic', null, null, 0, 4],
            ['Mythical Honor', 'mythic', null, null, 25, 5],
            ['Mythical Glory', 'mythic', null, null, 50, 6],
            ['Mythical Immortal', 'mythic', null, null, 100, 7],
        ];
        foreach ($tiers as $i => [$name, $kind, $divisions, $stars, $mythicFrom, $rate]) {
            DB::table('joki_tiers')->insert([
                'name' => $name, 'sort_order' => $i + 1, 'kind' => $kind,
                'divisions' => $divisions, 'stars_per_division' => $stars, 'mythic_from_stars' => $mythicFrom,
                'rate_rm' => $rate, 'express_rate_rm' => $rate + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn('joki_enabled');
        });
        Schema::dropIfExists('joki_orders');
        Schema::dropIfExists('joki_routes');
        Schema::dropIfExists('joki_tiers');
        Schema::dropIfExists('joki_settings');
    }
};
