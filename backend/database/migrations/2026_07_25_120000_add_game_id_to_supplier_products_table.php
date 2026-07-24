<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Founder feedback, 2026-07-25 (Price Sync Stage 2 UX revision):
     * curating 316 raw items one-by-one (re-picking a Game every
     * single time) doesn't scale — admin should link a whole
     * `category_raw` group (e.g. "Free Fire Global", ~15 items) to a
     * Game ONCE, then every item under it inherits that link. Nullable
     * — most rows start unlinked until an admin does this; a Stage 1
     * re-sync's upsert never touches this column (only
     * name/category_raw/price_sen/status_raw/last_synced_at), so a
     * link survives re-syncing.
     */
    public function up(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->foreignId('game_id')->nullable()->after('supplier_id')->constrained('games')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('game_id');
        });
    }
};
