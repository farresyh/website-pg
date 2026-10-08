<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * §16 item 70: full-voucher-cover orders created before the
     * CheckoutService fix kept the channel picked at checkout (4 on prod,
     * all `fpx`). A full cover is the only order with a voucher, RM 0 and
     * no gateway reference (ADR-024 decision 5). Idempotent; no down —
     * the old label was wrong, and the original channel stays in
     * `channel_code`.
     */
    public function up(): void
    {
        DB::table('orders')
            ->whereNotNull('voucher_id')
            ->where('final_amount', 0)
            ->whereNull('payment_ref')
            ->where('payment_method', '!=', 'voucher')
            ->update(['payment_method' => 'voucher']);
    }
};
