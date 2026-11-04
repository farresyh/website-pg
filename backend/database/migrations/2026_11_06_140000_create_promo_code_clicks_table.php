<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-118 V16: one row per `?promo=CODE` visit (one per browser tab), so
 * the V14 scorecard can count clicks for any date range.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_code_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['promo_code_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_clicks');
    }
};
