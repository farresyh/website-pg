<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'order_id',
        'code',
        'customer_email',
        'amount',
        'remaining',
        'status',
        'expires_at',
        'reason',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'amount' => 'integer',
        'remaining' => 'integer',
        'expires_at' => 'datetime',
    ];
}
