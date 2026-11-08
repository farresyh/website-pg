<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ADR-139 decision 10: a Malaysia month the rebate finalise task has run for. */
class ResellerRebateMonth extends Model
{
    protected $fillable = ['period', 'finalized_at'];

    protected $casts = ['period' => 'date', 'finalized_at' => 'datetime'];
}
