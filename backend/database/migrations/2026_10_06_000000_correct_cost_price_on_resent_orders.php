<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-105 2026-10-06 decision 18 (two-part rule). Before that addendum a
 * package-swap resend never wrote `orders.cost_price`, so a delivered
 * resent order kept its first package's cost in every COGS reader.
 *
 * For each delivered, non-test, non-combo order with a successful resend:
 * - `cost_price` = the latest successful resend's `cost_price_sen`;
 * - when `real_cost_price_sen` is null, `platform_profit` is rewritten to
 *   `selling_price − cost_price − affiliate_profit` too — but only if that
 *   equals the order's platform `order_profit` ledger sum (adjustments
 *   included). Otherwise the order is skipped and logged.
 *
 * The ledger is never touched. Query builder, not Eloquent, so no
 * OrderObserver broadcast fires. A second run writes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orders = DB::table('orders')
            ->where('delivery_status', 'delivered')
            ->where('is_test', false)
            ->whereExists(fn ($q) => $q->from('order_resend_attempts')
                ->whereColumn('order_resend_attempts.order_id', 'orders.id')
                ->where('attempt_type', 'resend')
                ->where('outcome', 'success'))
            ->whereNotExists(fn ($q) => $q->from('order_delivery_legs')
                ->whereColumn('order_delivery_legs.order_id', 'orders.id'))
            ->get(['id', 'cost_price', 'real_cost_price_sen', 'selling_price', 'affiliate_profit', 'platform_profit']);

        foreach ($orders as $order) {
            $newCost = (int) DB::table('order_resend_attempts')
                ->where('order_id', $order->id)
                ->where('attempt_type', 'resend')
                ->where('outcome', 'success')
                ->orderByDesc('id')
                ->value('cost_price_sen');

            $update = ['cost_price' => $newCost];

            if ($order->real_cost_price_sen === null) {
                $profit = (int) $order->selling_price - $newCost - (int) $order->affiliate_profit;
                $ledger = (int) DB::table('ledger_entries')
                    ->where('owner_type', 'platform')
                    ->where('type', 'order_profit')
                    ->where('reference_type', 'order')
                    ->where('reference_id', $order->id)
                    ->sum('amount');

                if ($profit !== $ledger) {
                    Log::warning('Resent-order cost correction skipped: residual disagrees with the ledger', [
                        'order_id' => $order->id, 'residual' => $profit, 'ledger' => $ledger,
                    ]);

                    continue;
                }

                $update['platform_profit'] = $profit;
            }

            $changed = array_filter($update, fn ($value, $column) => (int) $order->{$column} !== $value, ARRAY_FILTER_USE_BOTH);

            if ($changed !== []) {
                DB::table('orders')->where('id', $order->id)->update($changed);
            }
        }
    }

    /** A data correction: the old, wrong values are not restored. */
    public function down(): void {}
};
