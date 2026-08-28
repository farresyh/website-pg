<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-053 (REV-1..5) — a guest, order-linked review. `order_id` is
 * unique: at most one review per order (decision 2), the primary spam
 * control alongside the submission endpoint's own throttle. `game_id`/
 * `package_id` are deliberately NOT duplicated here — always read live
 * via the `order` relation (decision 5), since Order already carries
 * both and a snapshot would just be a second, driftable copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->timestamps();

            $table->unique('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
