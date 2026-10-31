<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-133 decision 8: who opened a KYC file, and when. */
class PartnerDocumentView extends Model
{
    public $timestamps = false;

    protected $fillable = ['document_id', 'admin_user_id', 'viewed_at'];

    protected $casts = ['viewed_at' => 'datetime'];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
