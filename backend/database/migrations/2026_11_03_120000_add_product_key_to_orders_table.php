<?php

use App\Models\Package;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-104 2026-11-03 addendum (PRD §16 item 94): what the customer bought,
 * snapshotted on the order (`Package::productKey()` and the package's name)
 * so Reports survive resend swaps, renamed or deleted packages and
 * recreated combos. Existing orders are backfilled from the package they
 * were sold at checkout: the order-level `initial` attempt's package, else
 * `orders.package_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('product_key', 100)->nullable()->after('package_id');
            $table->string('product_name')->nullable()->after('product_key');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['product_key', 'product_name']);
        });
    }

    /** Idempotent: only orders with no product yet. */
    public function backfill(): void
    {
        DB::table('orders')->whereNull('product_key')->orderBy('id')->select(['id', 'game_id', 'package_id'])->chunkById(500, function ($orders) {
            $bought = DB::table('order_resend_attempts')
                ->whereIn('order_id', $orders->pluck('id'))
                ->where('attempt_type', 'initial')
                ->whereNull('order_delivery_leg_id')
                ->whereNotNull('package_id')
                ->pluck('package_id', 'order_id');

            $packageIds = $orders->map(fn ($order) => $bought[$order->id] ?? $order->package_id)->filter()->unique();
            $packages = DB::table('packages')->whereIn('id', $packageIds)->get(['id', 'game_id', 'name', 'denomination', 'catalog_code'])->keyBy('id');

            foreach ($orders as $order) {
                $packageId = $bought[$order->id] ?? $order->package_id;
                $package = $packageId !== null ? $packages->get($packageId) : null;

                DB::table('orders')->where('id', $order->id)->update([
                    'product_key' => $package !== null
                        ? Package::productKeyFor((int) $package->game_id, $package->denomination === null ? null : (int) $package->denomination, $package->catalog_code, (int) $package->id)
                        : Package::productKeyFor($order->game_id === null ? null : (int) $order->game_id, null, null, $packageId === null ? null : (int) $packageId),
                    'product_name' => $package?->name,
                ]);
            }
        });
    }
};
