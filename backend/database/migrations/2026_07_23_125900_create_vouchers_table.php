<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The only refund mechanism in the system (ADR-004). `remaining` is
     * decremented via a locked, atomic operation (VCH-5) — never a plain
     * update, to prevent double-spend under concurrent redemption.
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('customer_email');
            $table->unsignedBigInteger('amount'); // sen, as issued
            $table->unsignedBigInteger('remaining'); // sen, decremented on redemption
            $table->string('status')->default('active'); // active|exhausted|expired|revoked
            $table->timestamp('expires_at')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
