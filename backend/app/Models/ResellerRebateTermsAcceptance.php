<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ADR-139 accountant addendum, item 5: a reseller accepted a version of the programme terms. */
class ResellerRebateTermsAcceptance extends Model
{
    protected $fillable = ['reseller_id', 'terms_version', 'affiliate_user_id', 'accepted_at'];

    protected $casts = ['accepted_at' => 'datetime', 'terms_version' => 'integer'];
}
