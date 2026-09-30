<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-116 decision 5: opt-in is per phone number, not per order, and lasts
 * across orders and brands. A number is opted in once it messages the
 * customer-support number with an order number (the updates button or the
 * support button). `opted_out_at` is set by replying STOP and stops Delivered
 * receipts only, never voucher messages. `phone` is the normalised MSISDN
 * (App\Support\PhoneNumber).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->timestamp('opted_in_at');
            $table->string('opt_in_source', 20); // message (updates | support on rows before the 2026-09-30 addendum)
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contacts');
    }
};
