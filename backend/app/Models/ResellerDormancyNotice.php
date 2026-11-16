<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-142 decision 24: a dormancy warning or deactivation notice, recorded
 * once per reseller, kind and idle spell (`anchor_on`, the Malaysia date of
 * the last activity), so a rerun never sends twice.
 */
class ResellerDormancyNotice extends Model
{
    public const UPDATED_AT = null;

    public const WARNING = 'warning';

    public const DEACTIVATED = 'deactivated';

    protected $fillable = ['reseller_id', 'kind', 'anchor_on'];

    protected $casts = ['anchor_on' => 'date'];
}
