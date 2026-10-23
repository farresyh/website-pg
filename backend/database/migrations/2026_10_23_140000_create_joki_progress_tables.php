<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-126 PR 3 (decisions 10 and 21): each progress update a penjoki
 * (`.progress`) or an admin records, the order's current rank, and the
 * panel's minimum gap between two WhatsApp progress messages to one
 * customer (default 2 hours).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('joki_progress_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->json('rank');
            $table->string('actor');
            $table->string('channel', 20); // admin | supplier_bot
            $table->timestamp('created_at')->useCurrent();
            $table->index('order_id');
        });

        Schema::table('joki_orders', function (Blueprint $table) {
            $table->json('current_rank')->nullable()->after('to_rank');
            $table->timestamp('current_rank_at')->nullable()->after('current_rank');
        });

        Schema::table('joki_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('progress_message_interval_minutes')->default(120)->after('terms_html');
        });
    }

    public function down(): void
    {
        Schema::table('joki_settings', function (Blueprint $table) {
            $table->dropColumn('progress_message_interval_minutes');
        });

        Schema::table('joki_orders', function (Blueprint $table) {
            $table->dropColumn(['current_rank', 'current_rank_at']);
        });

        Schema::dropIfExists('joki_progress_updates');
    }
};
