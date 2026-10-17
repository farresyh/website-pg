<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §16 item 120 (ADR-083 2026-10-18 addendum): keep the original CHIP
 * settlement .xlsx. `file_path` is on the accounting disk; `file_sha256`
 * proves the stored file is the one reconciled, and its unique index
 * stops one file being attached to two settlements. Both stay null on
 * the four settlements uploaded before this, until their original is
 * attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settlements', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('original_filename');
            $table->char('file_sha256', 64)->nullable()->unique()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('payment_settlements', function (Blueprint $table) {
            $table->dropUnique(['file_sha256']);
            $table->dropColumn(['file_path', 'file_sha256']);
        });
    }
};
