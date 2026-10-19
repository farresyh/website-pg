<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-124 decisions 5, 6 and 11: one append-only row per reveal or
 * replace of a secret field. `actor_type` is `admin` today and `supplier`
 * from ADR-125 PR 2 (2026-10-18 addendum, decision 6). The order FK is
 * nulled, not cascaded, so the audit outlives a deleted sandbox order.
 * Pruned after one year by `app:prune-credential-reveals`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_reveals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->index()->constrained('orders')->nullOnDelete();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 16);
            $table->string('field_key', 64);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_reveals');
    }
};
