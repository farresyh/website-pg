<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-027's 2026-08-29 addendum, decisions 23/26/27: one row per
     * OTP request (never overwritten in place) — `code_hash` only, the
     * plain code is never persisted, matching this codebase's own
     * discipline for anything credential-shaped. `attempts` enforces
     * decision 26's brute-force lockout independent of the request
     * -level throttle; `consumed_at` prevents replaying an already-used
     * code. Not unique on `email` — a fresh generate() for the same
     * email creates a new row, and OtpService::verify() only ever
     * matches the newest one.
     */
    public function up(): void
    {
        Schema::create('membership_otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_otp_codes');
    }
};
