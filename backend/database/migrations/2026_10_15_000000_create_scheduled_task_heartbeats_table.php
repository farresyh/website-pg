<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-122: one row per scheduled task, written by
 * RecordScheduledTaskHeartbeat and read by SchedulerLiveness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamp('last_ok_at')->nullable();
            $table->decimal('last_runtime_seconds', 10, 2)->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_heartbeats');
    }
};
