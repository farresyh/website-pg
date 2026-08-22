<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-029 decision 3 (execution mechanism settled by decision 9):
     * reseller-scoped, exact-path matching only (addendum 2 decision
     * 15 explicitly rejected regex/CSV import for this MVP).
     * `hit_count` (addendum 2 decision 15) is incremented by
     * middleware.ts's lookup, via the public record-hit endpoint.
     */
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->cascadeOnDelete();
            $table->string('from_path');
            $table->string('to_path');
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamps();

            $table->unique(['reseller_id', 'from_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
