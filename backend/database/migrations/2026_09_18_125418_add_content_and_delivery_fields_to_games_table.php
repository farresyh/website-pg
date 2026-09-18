<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-109 decision 1. `delivery_mode` defaults `'instant'` and
     * `delivery_subtext` stays nullable (app-layer fallback, decision
     * 1) so every existing game keeps rendering exactly what
     * ProductHeaderCard hardcodes today with zero admin action.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->text('description')->nullable()->after('banner_url');
            $table->json('important_notes')->nullable()->after('description');
            $table->enum('delivery_mode', ['instant', 'manual'])->default('instant')->after('important_notes');
            $table->string('delivery_subtext')->nullable()->after('delivery_mode');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['description', 'important_notes', 'delivery_mode', 'delivery_subtext']);
        });
    }
};
