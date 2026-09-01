<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-051 (MID-10/11, MUI-9): one row per outbound supplier-adapter
     * HTTP call (or per circuit-breaker-preempted skip). `supplier_id`/
     * `order_id` are nullOnDelete, not cascadeOnDelete — same
     * deliberate convention `deactivation_logs` already uses toward
     * `price_sync_run_id` — deleting a Supplier or Order must never
     * silently wipe out the debug trail that could explain why it was
     * deleted in the first place.
     */
    public function up(): void
    {
        Schema::create('supplier_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            // checkBalance|listProducts|createOrder|checkStatus|validatePlayer
            $table->string('call_type');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // Null for a 'skipped_breaker_open' row — no HTTP call was
            // ever attempted, so there's no method/url to record.
            $table->string('method')->nullable();
            $table->text('url')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            // success|failure|exception|skipped_breaker_open
            $table->string('outcome');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'created_at']);
            $table->index(['call_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_request_logs');
    }
};
