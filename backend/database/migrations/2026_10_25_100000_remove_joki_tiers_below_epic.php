<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-126 2026-10-25 addendum: joki is offered from Epic up (founder), so
 * Warrior, Elite, Master and Grandmaster leave the rank model and the
 * ladder starts at Epic V 0★. Joki was never released, so no production
 * route or order can reference them; a route that does refuses the
 * migration rather than losing its rank. An order keeps its own rank
 * snapshot (label included), so it is untouched.
 */
return new class extends Migration
{
    private const REMOVED = ['Warrior', 'Elite', 'Master', 'Grandmaster'];

    public function up(): void
    {
        $ids = DB::table('joki_tiers')->whereIn('name', self::REMOVED)->pluck('id');

        $routes = DB::table('joki_routes')->whereIn('from_tier_id', $ids)->orWhereIn('to_tier_id', $ids)->count();
        if ($routes > 0) {
            throw new RuntimeException("{$routes} joki route(s) start or end below Epic; move them to Epic or above first.");
        }

        DB::table('joki_tiers')->whereIn('id', $ids)->delete();
    }

    /** Puts the four tiers back at their old places, with the draft rates (RM2, Express RM3). */
    public function down(): void
    {
        $now = now();
        foreach ([['Warrior', 3, 3], ['Elite', 4, 4], ['Master', 4, 4], ['Grandmaster', 5, 5]] as $i => [$name, $divisions, $stars]) {
            DB::table('joki_tiers')->insertOrIgnore([
                'name' => $name, 'sort_order' => $i + 1, 'kind' => 'divisional', 'divisions' => $divisions, 'stars_per_division' => $stars,
                'rate_rm' => 2, 'express_rate_rm' => 3, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
