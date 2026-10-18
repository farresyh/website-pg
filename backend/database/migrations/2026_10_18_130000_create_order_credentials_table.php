<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-124 (+ its 2026-10-18 addendum): a customer's game-account login,
 * one row per order, never columns on `orders` (an order's toArray()
 * reaches every admin list). `secrets` is the encrypted JSON of the secret
 * fields; `fields` and `secret_fields` are plain (non-secret values, and
 * the secret fields' labels so a wiped row still shows what was there).
 * `app:wipe-order-credentials` nulls `secrets` and sets `purged_at`;
 * consent is kept. Left out of every DB dump (config/database.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->json('fields');
            $table->json('secret_fields');
            $table->text('secrets')->nullable();
            $table->timestamp('secrets_set_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('purged_at')->nullable()->index();
            $table->timestamp('consented_at');
            $table->text('consent_text');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_credentials');
    }
};
