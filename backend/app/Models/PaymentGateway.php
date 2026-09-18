<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-110 PR-C — mirrors `Supplier` (ADR-046, SUPP-5) verbatim: same
 * `encrypted:array` cast, same `$hidden` treatment so `api_config`
 * never leaks via a plain `toArray()`/`toJson()`.
 */
class PaymentGateway extends Model
{
    protected $fillable = [
        'gateway_key',
        'api_config',
    ];

    protected $hidden = [
        'api_config',
    ];

    protected $casts = [
        'api_config' => 'encrypted:array',
    ];
}
