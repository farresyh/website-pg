<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-019 fix-now item: the default, unfiltered Admin Orders listing
 * sorts by created_at with nothing backing that sort — a full table
 * scan on every unfiltered page load. Paired with OrderController's
 * whereDate('created_at', ...) fix, which otherwise defeats this index
 * regardless (whereDate() wraps the column in a function).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
