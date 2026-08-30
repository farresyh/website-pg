<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-058 58b (RES-6): reseller deletion is soft-delete only. An
     * `orders.reseller_id` FK points here and that history must survive
     * a "deleted" reseller (ADR-057's backfill assumes every order row
     * always resolves to a Reseller). The controller additionally blocks
     * the delete unless earnings balance = 0 and there is no pending
     * withdrawal — soft-delete is the storage mechanism, not the guard.
     */
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
