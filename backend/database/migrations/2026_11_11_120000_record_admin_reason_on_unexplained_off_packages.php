<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-138 decision 3: an admin's switch-off used to store no reason, so those
 * packages never reached Dismissed. Sale state doesn't change. The Joki pool
 * is left alone: it's switched in Joki settings, not Dismissed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('packages')
            ->where('is_active', false)
            ->whereNull('deactivated_reason')
            ->whereNotIn('supplier_id', DB::table('suppliers')->where('joki_role', 'pool')->select('id'))
            ->update(['deactivated_reason' => 'admin', 'deactivated_at' => now()]);
    }

    public function down(): void
    {
        // Which rows had no reason isn't recorded, and the reason changes nothing for sale.
    }
};
