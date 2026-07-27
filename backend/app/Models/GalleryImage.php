<?php

namespace App\Models;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * IMG-1/IMG-2 — an uploaded image usable as `Game.image_url` /
 * `HeroSlide.image_url` (admins copy `url` from here into either
 * field; no FK from Game/HeroSlide to this table — see
 * GalleryImageController's doc comment for why).
 */
class GalleryImage extends Model
{
    protected $fillable = [
        'original_name',
        'disk',
        'path',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'uploaded_by');
    }
}
