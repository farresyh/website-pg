<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-126 2026-10-23 addendum, decision 17: a supplier group post's dedupe
 * key gains the supplier (`…:ref:<ref>:supplier:<id>`), so a reassigned
 * order posts to its new group. Existing rows take the order's current
 * supplier: nothing reassigns an order yet, so it is the one they went to.
 * Idempotent: a row already carrying `:supplier:` is left alone.
 */
return new class extends Migration
{
    private const EVENTS = ['supplier_order_post', 'supplier_sla_reminder'];

    public function up(): void
    {
        DB::table('customer_notifications')
            ->join('orders', 'orders.id', '=', 'customer_notifications.order_id')
            ->whereIn('customer_notifications.event', self::EVENTS)
            ->where('customer_notifications.dedupe_key', 'not like', '%:supplier:%')
            ->whereNotNull('orders.supplier_id')
            ->orderBy('customer_notifications.id')
            ->select('customer_notifications.id', 'customer_notifications.dedupe_key', 'orders.supplier_id')
            ->get()
            ->each(function (object $row) {
                DB::table('customer_notifications')->where('id', $row->id)->update([
                    'dedupe_key' => "{$row->dedupe_key}:supplier:{$row->supplier_id}",
                ]);
            });
    }

    public function down(): void
    {
        DB::table('customer_notifications')
            ->whereIn('event', self::EVENTS)
            ->where('dedupe_key', 'like', '%:supplier:%')
            ->orderBy('id')
            ->get()
            ->each(function (object $row) {
                DB::table('customer_notifications')->where('id', $row->id)->update([
                    'dedupe_key' => preg_replace('/:supplier:\d+$/', '', $row->dedupe_key),
                ]);
            });
    }
};
