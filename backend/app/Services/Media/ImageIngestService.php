<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * ADR-060 PR-6 (planning addendum decision 4): the one seam every
 * affiliate-uploaded image goes through — logo (`BrandingController`)
 * and hero slides (`HeroSlideController`).
 *
 * Always re-encodes to WebP, caps the longest edge, applies and then
 * strips EXIF orientation. Writes through
 * `config('filesystems.gallery_disk')` — never a hardcoded `/storage/`
 * path — so the future Cloudflare R2 cutover is a config + one-off data
 * migration, never a code change. Returns the stored disk PATH; the
 * caller derives the URL (via the same disk) so the URL base can move.
 *
 * `intervention/image` v3 was chosen over raw GD: it applies EXIF
 * orientation automatically (a phone photo uploaded sideways) and does
 * not depend on the host's GD build being compiled with WebP — both
 * real gaps in the GD-only approach the grill first landed on.
 */
class ImageIngestService
{
    private const WEBP_QUALITY = 82;

    public function __construct(private readonly ImageManager $images) {}

    /**
     * @param  string  $path  the destination disk path, e.g.
     *                        "affiliate-logos/7.webp" — the caller owns
     *                        the naming scheme (fixed for a logo,
     *                        uuid-based for a hero slide).
     * @param  int  $maxEdge  longest edge in px; the image is only ever
     *                        scaled DOWN, never up, aspect ratio kept.
     */
    public function ingest(UploadedFile $file, string $path, int $maxEdge): string
    {
        $image = $this->images->read($file->getRealPath());
        $image->orient();
        $image->scaleDown($maxEdge, $maxEdge);

        Storage::disk($this->disk())->put($path, (string) $image->toWebp(self::WEBP_QUALITY), 'public');

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk($this->disk())->delete($path);
    }

    public function url(string $path): string
    {
        return Storage::disk($this->disk())->url($path);
    }

    private function disk(): string
    {
        return config('filesystems.gallery_disk');
    }
}
