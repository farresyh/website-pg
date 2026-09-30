<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-116 decisions 9/10: one row per outbound customer WhatsApp message,
 * plus the platform-wide master switch (default OFF).
 *
 * `dedupe_key` (e.g. `voucher_issued:voucher:12`) is what makes a repeated
 * event or a job retry never send twice. `phone` is the normalised MSISDN
 * actually messaged, never the customer's raw input. `message` keeps the
 * exact text queued, so the admin sees what the customer was told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);
            $table->string('dedupe_key', 100)->unique();
            $table->foreignId('order_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('phone', 20)->nullable();
            $table->text('message');
            $table->string('status', 20); // queued | sent | failed | skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::table('platform_settings', function (Blueprint $table) {
            $table->boolean('whatsapp_notifications_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('whatsapp_notifications_enabled');
        });

        Schema::dropIfExists('customer_notifications');
    }
};
