<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-100 — the audit trail for `PendingReactivationAutoApprover`'s
 * writes. Deliberately a new, sibling table to `deactivation_logs`
 * rather than a widened `deactivation_logs` (a new `action` column
 * there would blur a table whose name, and every existing reader,
 * assumes "this row is a deactivation") — mirrors that table's own
 * "one row per thing that actually happened" discipline (ADR-015
 * decision #2), not a generic audit log.
 *
 * A manual Approve via `PendingReactivationController::approve()` /
 * `bulkApprove()` writes no row here — this table is scoped to what
 * `PendingReactivationAutoApprover` itself did, the one path with no
 * human decision behind it. `trigger` records *why* the auto-approve
 * fired, since the two gates mean genuinely different things: a
 * cutoff-window match is Digiflazz's own documented, predictable
 * schedule (no waiting involved); a stability-confirmed one is this
 * project's own N-consecutive-sync guard actually doing its job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_reactivation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger'); // 'cutoff_window' | 'stability_confirmed'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_reactivation_logs');
    }
};
