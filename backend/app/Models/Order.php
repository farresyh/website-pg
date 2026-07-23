<?php

namespace App\Models;

use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'reference_number',
        'customer_email',
        'customer_phone',
        'player_id',
        'server_id',
        'game_id',
        'package_id',
        'supplier_id',
        'reseller_id',
        'cost_price',
        'reseller_cost_price',
        'reseller_markup_pct',
        'selling_price',
        'voucher_discount',
        'transaction_fee',
        'final_amount',
        'platform_profit',
        'reseller_profit',
        'payment_status',
        'delivery_status',
        'payment_method',
        'payment_ref',
        'supplier_ref',
        'supplier_response',
    ];

    protected $casts = [
        'cost_price' => 'integer',
        'reseller_cost_price' => 'integer',
        'reseller_markup_pct' => 'decimal:2',
        'selling_price' => 'integer',
        'voucher_discount' => 'integer',
        'transaction_fee' => 'integer',
        'final_amount' => 'integer',
        'platform_profit' => 'integer',
        'reseller_profit' => 'integer',
        'payment_status' => PaymentStatus::class,
        'delivery_status' => DeliveryStatus::class,
        'supplier_response' => 'array',
    ];
}
