<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-039 decision 9: one row per `app:run-backup` execution
     * (scheduled or manual), unified history for `/middleware/backups`
     * — same one-row-per-run pattern as `price_sync_runs`.
     * `restore_test_passed`/`restore_test_details` record decision 8's
     * automatic restore-test result for that run, not just pass/fail
     * logged and discarded.
     */
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status'); // queued|running|success|failed
            $table->string('triggered_by'); // 'system' (scheduled) or an admin's name (manual)
            $table->string('disk')->nullable();
            $table->string('path')->nullable(); // storage path of the created zip, for download/delete
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('restore_test_passed')->nullable();
            $table->json('restore_test_details')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
