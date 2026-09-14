<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gallery\UploadGalleryImageRequest;
use App\Models\AffiliateBranding;
use App\Models\GalleryImage;
use App\Models\Game;
use App\Models\HeroSlide;
use App\Services\Media\ImageIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * IMG-1/IMG-2 — image upload + browse/search/delete for game and hero
 * -slide assets. Deliberately no FK from Game/HeroSlide to this
 * table: an admin uploads here, copies the resulting `url` into
 * `EditGameModal`/the Hero Slide modal's existing `image_url` text
 * field (unchanged), same as pasting any other external URL today —
 * this only replaces "paste a URL you hosted elsewhere" with "upload
 * it here and copy the URL," it doesn't couple Game/HeroSlide's
 * lifecycle to this table (deleting a gallery row never cascades into
 * a Game/HeroSlide edit — `references()` below surfaces what would be
 * affected so the admin decides, rather than a hard DB constraint).
 *
 * Storage is behind Laravel's own Storage facade, disk resolved from
 * `config('filesystems.gallery_disk')` (defaults to `public` — local,
 * `storage/app/public` symlinked via `php artisan storage:link`; `r2_gallery`
 * once `GALLERY_DISK` is set — ADR-095) so a disk swap costs an env
 * change, not a rewrite of this controller.
 */
class GalleryImageController extends Controller
{
    /**
     * ADR-095: bigger than logo (512)/hero (1600) — Gallery serves
     * game art/promo banners that can legitimately need more
     * resolution than a fixed hero slide.
     */
    private const MAX_EDGE = 2000;

    public function index(Request $request): JsonResponse
    {
        $query = GalleryImage::query();

        if ($search = $request->query('search')) {
            $query->where('original_name', 'like', "%{$search}%");
        }

        $perPage = (int) $request->query('per_page', 24);

        return response()->json(
            // `id` as a tiebreaker: `created_at` is second-precision
            // (same class of gap as ADR-015's timestamp-comparison
            // bug), so two uploads within the same second would
            // otherwise sort arbitrarily.
            $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($perPage)->withQueryString(),
        );
    }

    public function store(UploadGalleryImageRequest $request, ImageIngestService $ingest): JsonResponse
    {
        $file = $request->file('image');
        // ADR-019: was hardcoded to 'public' — now driven by
        // config/filesystems.php's gallery_disk (GALLERY_DISK env, not
        // the app-wide FILESYSTEM_DISK default, which this env already
        // sets to 'local' for unrelated reasons and has no public URL).
        $disk = config('filesystems.gallery_disk');
        // ADR-095: reuses the same seam logo/hero uploads already go
        // through, rather than a second parallel implementation —
        // always re-encodes to WebP (source mime is irrelevant once
        // ingested, including gif — no animated-GIF preservation,
        // confirmed not a real use case) and resizes down to
        // self::MAX_EDGE, never up.
        $path = $ingest->ingest($file, 'gallery/'.Str::random(40).'.webp', self::MAX_EDGE);

        $image = GalleryImage::query()->create([
            'original_name' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            // The stored bytes are always WebP now, regardless of what
            // the browser sent — recording the original upload's mime
            // type here would be misleading.
            'mime_type' => 'image/webp',
            'size_bytes' => Storage::disk($disk)->size($path),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($image, 201);
    }

    /**
     * ADR-095: pre-flight check for the admin UI's delete-confirmation
     * dialog — reverse-scans every plain-text field an admin could have
     * pasted this image's URL/path into (no FK exists to enforce this
     * at the DB layer, see the class doc comment). Read-only; never
     * blocks `destroy()` itself, which stays a straightforward delete —
     * the frontend decides whether to warn before ever calling it.
     */
    public function references(GalleryImage $galleryImage): JsonResponse
    {
        $url = $galleryImage->url;
        $references = [];

        foreach (Game::query()->where('image_url', $url)->orWhere('banner_url', $url)->orWhere('seo_og_image', $url)->get() as $game) {
            foreach (['image_url' => 'image_url', 'banner_url' => 'banner_url', 'seo_og_image' => 'SEO image'] as $column => $label) {
                if ($game->{$column} === $url) {
                    $references[] = "Game — {$game->name} ({$label})";
                }
            }
        }

        foreach (HeroSlide::query()->where('image_url', $url)->get() as $slide) {
            $references[] = "Hero Slide — {$slide->title}";
        }

        foreach (AffiliateBranding::query()->where('logo_path', $galleryImage->path)->orWhere('favicon_path', $galleryImage->path)->get() as $branding) {
            if ($branding->logo_path === $galleryImage->path) {
                $references[] = "Affiliate Branding — {$branding->store_name} (logo)";
            }
            if ($branding->favicon_path === $galleryImage->path) {
                $references[] = "Affiliate Branding — {$branding->store_name} (favicon)";
            }
        }

        return response()->json(['references' => $references]);
    }

    public function destroy(GalleryImage $galleryImage): JsonResponse
    {
        Storage::disk($galleryImage->disk)->delete($galleryImage->path);
        $galleryImage->delete();

        return response()->json(null, 204);
    }
}
