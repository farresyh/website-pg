<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-094 decision 1: one row per distinct component of a combo
     * Package. `quantity` lets the same component repeat (decision 4's
     * own example — a fixed denomination ×2) without a second row for
     * the same pair — `unique(combo_package_id, component_package_id)`
     * enforces exactly one row per distinct component. `sort_order`
     * fixes the leg order `OrderFulfillmentService` walks at
     * fulfillment time (decision 7) — same idiom as `packages.sort_order`.
     *
     * `combo_package_id` cascades on delete (deleting a combo Package
     * cleans up its own component list); `component_package_id`
     * restricts (an underlying Package can't be deleted out from under
     * a combo that still references it — same restrictOnDelete posture
     * `packages.supplier_id` already has).
     */
    public function up(): void
    {
        Schema::create('package_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignId('component_package_id')->constrained('packages')->restrictOnDelete();
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['combo_package_id', 'component_package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_components');
    }
};
