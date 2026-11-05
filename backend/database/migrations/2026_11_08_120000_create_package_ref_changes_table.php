<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-137 decision 21: one row per package repointed to a new supplier
 * product when a supplier changes system. The cost change itself is a
 * PriceChangeLog row; this records the reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_ref_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->string('old_ref');
            $table->string('new_ref');
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index('package_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_ref_changes');
    }
};
