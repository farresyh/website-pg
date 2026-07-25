<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-014: two indexes found missing by a read-only audit of the live
 * codebase — payment_ref is the exact column every Xendit webhook
 * looks up by (XenditWebhookController::handle()), and
 * (payment_status, delivery_status) backs every OrderController
 * status filter (need_action/processing/completed/today). Both were
 * full table scans before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('payment_ref');
            $table->index(['payment_status', 'delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_ref']);
            $table->dropIndex(['payment_status', 'delivery_status']);
        });
    }
};
