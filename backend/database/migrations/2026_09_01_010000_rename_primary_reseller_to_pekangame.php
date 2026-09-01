<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-062: rebrand the primary storefront to "PekanGame".
 *
 * The customer-facing brand string flows from `reseller_branding.store_name`
 * (ADR-028), falling back to `resellers.business_name` when no branding row
 * exists (BrandingController). This migration renames the primary reseller
 * row from ADR-013's "Platform Owner" placeholder to "PekanGame" and ensures
 * an explicit branding row so the storefront never renders the internal
 * placeholder.
 *
 * Guarded: resolved only when the primary row exists — a fresh test DB with
 * no resellers is left untouched. Post-ADR-061 no live code path keys on the
 * literal `business_name` value (all swapped to `Reseller::primary()` /
 * `is_primary`); the three historical migrations that still look the row up
 * by `where('business_name', 'Platform Owner')` run before this one on a
 * `migrate:fresh`, so they create the row under the old name and this
 * migration then renames it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $primaryId = DB::table('resellers')->where('is_primary', true)->value('id')
            ?? DB::table('resellers')->where('business_name', 'Platform Owner')->value('id');

        if ($primaryId === null) {
            return;
        }

        DB::table('resellers')->where('id', $primaryId)->update([
            'business_name' => 'PekanGame',
        ]);

        $branding = DB::table('reseller_branding')->where('reseller_id', $primaryId)->first();

        if ($branding === null) {
            DB::table('reseller_branding')->insert([
                'reseller_id' => $primaryId,
                'store_name' => 'PekanGame',
                'description' => 'Fast, secure game top-ups delivered in minutes.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif (in_array($branding->store_name, ['Platform Owner', 'Kedai Runcit Soloz'], true)) {
            DB::table('reseller_branding')->where('reseller_id', $primaryId)->update([
                'store_name' => 'PekanGame',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $primaryId = DB::table('resellers')->where('is_primary', true)->value('id');

        if ($primaryId === null) {
            return;
        }

        DB::table('resellers')->where('id', $primaryId)->update([
            'business_name' => 'Platform Owner',
        ]);

        DB::table('reseller_branding')
            ->where('reseller_id', $primaryId)
            ->where('store_name', 'PekanGame')
            ->update(['store_name' => 'Platform Owner', 'updated_at' => now()]);
    }
};
