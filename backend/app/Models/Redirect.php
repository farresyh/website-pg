<?php

namespace App\Models;

use App\Models\Concerns\BelongsToReseller;
use Illuminate\Database\Eloquent\Model;

/** ADR-029 decision 3/9: exact-path redirect, reseller-scoped, hit-counted (addendum 2 decision 15). */
class Redirect extends Model
{
    /** ADR-057: tenant-scoped to the current reseller under the reseller guard. */
    use BelongsToReseller;

    protected $fillable = [
        'reseller_id',
        'from_path',
        'to_path',
        'status_code',
        'hit_count',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'hit_count' => 'integer',
    ];
}
