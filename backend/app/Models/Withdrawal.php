<?php

namespace App\Models;

use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'amount',
        'bank_name',
        'bank_account_no',
        'bank_account_holder',
        'status',
        'admin_note',
        'requested_by',
        'reseller_user_id',
        'approved_by',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'status' => WithdrawalStatus::class,
        'processed_at' => 'datetime',
    ];
}
