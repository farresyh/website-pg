<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-068 2026-10-13 addendum, decisions 7-8: one row per renewal
 * reminder. The unique (membership, kind, expires_at) is the dedupe —
 * one `before` and one `after` per paid-through period, and a renewal
 * (which always moves `expires_at`) starts a fresh pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // before | after
            $table->timestamp('expires_at');
            $table->string('status', 10); // queued | sent | failed | skipped
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['membership_id', 'kind', 'expires_at'], 'membership_reminders_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_reminders');
    }
};
