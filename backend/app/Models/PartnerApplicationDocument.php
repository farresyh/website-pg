<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ADR-133 decision 8: one uploaded KYC file, on a private disk, never a public URL. */
class PartnerApplicationDocument extends Model
{
    protected $fillable = ['partner_application_id', 'kind', 'disk', 'path', 'original_name', 'mime', 'size_bytes'];

    protected $hidden = ['disk', 'path'];

    protected $casts = ['size_bytes' => 'integer'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(PartnerApplication::class, 'partner_application_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(PartnerDocumentView::class, 'document_id');
    }
}
