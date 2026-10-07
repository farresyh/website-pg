<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ADR-120 decision 13: platform-wide FAQ, admin-authored; `{store_name}` resolves per brand at read time. */
class Faq extends Model
{
    protected $fillable = [
        'question',
        'answer',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
