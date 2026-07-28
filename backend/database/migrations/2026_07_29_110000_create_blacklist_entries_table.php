<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-007 / FRAUD-1..3 — internal fraud blacklist, independent of
 * whatever a supplier may or may not offer. `is_active` (not a hard
 * delete on removal, FRAUD-3) so a blocked-attempt history
 * (blacklist_hits) can always trace back to the entry that caused it,
 * even after an admin later removes the entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('value');
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'value', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklist_entries');
    }
};
