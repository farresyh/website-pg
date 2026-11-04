<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ADR-118 V16: one `?promo=CODE` visit, counted by the scorecard. */
class PromoCodeClick extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['promo_code_id'];

    protected $casts = ['promo_code_id' => 'integer'];
}
