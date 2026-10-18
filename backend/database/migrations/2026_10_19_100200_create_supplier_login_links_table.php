<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-125 decisions 50-52 (ADR-124 2026-10-19 addendum): the supplier's
 * short login link. Only the token's SHA-256 is stored, so a DB dump
 * holds no working link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_login_links', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('reference_number');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_login_links');
    }
};
