<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-124 decisions 5, 6 and 11: one row per reveal or replace of a
 * secret field. Append-only by convention; `app:prune-credential-reveals`
 * is the only thing that deletes, after one year.
 */
class CredentialReveal extends Model
{
    public const ACTOR_ADMIN = 'admin';

    public const ACTION_REVEAL = 'reveal';

    public const ACTION_REPLACE = 'replace';

    /** ADR-125 decision 43: an admin corrected the identifier on resend. */
    public const ACTION_IDENTIFIER = 'identifier';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'actor_type',
        'actor_id',
        'action',
        'field_key',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
