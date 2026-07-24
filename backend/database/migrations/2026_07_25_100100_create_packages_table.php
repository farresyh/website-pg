<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A specific top-up denomination within a Game (PRD §8). Money
     * fields are integer sen, same convention as `orders`/
     * `ledger_entries`. `cost_price`/`reseller_cost_price` are
     * admin-managed/stored (GAME-7/9/10/11), never recomputed live
     * from a percentage at order time — the customer-facing
     * `selling_price` is computed per-reseller at order time
     * (Order.selling_price), not stored here.
     *
     * `supplier_package_ref` format varies per supplier — confirmed
     * from the real Gamevion catalog ("GV733", "31478", "FFMY_100").
     */
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('cost_price'); // sen — supplier wholesale
            $table->unsignedInteger('reseller_cost_price'); // sen — cost_price + system markup
            $table->boolean('is_active')->default(true);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('supplier_package_ref');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
