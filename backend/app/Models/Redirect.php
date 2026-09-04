<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAffiliate;
use Illuminate\Database\Eloquent\Model;

/** ADR-029 decision 3/9: exact-path redirect, affiliate-scoped, hit-counted (addendum 2 decision 15). */
class Redirect extends Model
{
    /** ADR-057: tenant-scoped to the current affiliate under the affiliate guard. */
    use BelongsToAffiliate;

    protected $fillable = [
        'affiliate_id',
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
