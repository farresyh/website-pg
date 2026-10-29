<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-131 decisions 19-21: a branded domain takes no CHIP payment until an admin approves it
 * (CHIP's activation condition: no branded store uses its payment services without CHIP's prior
 * written approval). Nullable: null = not approved. The platform's own seeded domain rows (the
 * primary affiliate's) are approved here, so nothing of ours stops; every other row, including an
 * `is_owned` brand's, stays unapproved until an admin approves it. Written out, not read from a
 * model, so this migration never changes when the code does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_domains', function (Blueprint $table) {
            $table->timestamp('payments_approved_at')->nullable();
            $table->foreignId('payments_approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
        });

        DB::table('affiliate_domains')
            ->whereIn('affiliate_id', DB::table('affiliates')->where('is_primary', true)->select('id'))
            ->update(['payments_approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('affiliate_domains', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payments_approved_by');
            $table->dropColumn('payments_approved_at');
        });
    }
};
