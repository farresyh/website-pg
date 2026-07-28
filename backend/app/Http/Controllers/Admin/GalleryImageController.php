<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gallery\UploadGalleryImageRequest;
use App\Models\GalleryImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * IMG-1/IMG-2 — image upload + browse/search/delete for game and hero
 * -slide assets. Deliberately no FK from Game/HeroSlide to this
 * table: an admin uploads here, copies the resulting `url` into
 * `EditGameModal`/the Hero Slide modal's existing `image_url` text
 * field (unchanged), same as pasting any other external URL today —
 * this only replaces "paste a URL you hosted elsewhere" with "upload
 * it here and copy the URL," it doesn't couple Game/HeroSlide's
 * lifecycle to this table (deleting a gallery row never cascades into
 * a Game/HeroSlide edit).
 *
 * Storage is behind Laravel's own Storage facade, disk resolved from
 * `config('filesystems.gallery_disk')` (defaults to `public` — local,
 * `storage/app/public` symlinked via `php artisan storage:link`) so a
 * later swap to a real bucket (s3/r2, once production hosting is
 * decided — see ADR-010/ADR-020) costs a `GALLERY_DISK` env change,
 * not a rewrite of this controller.
 */
class GalleryImageController extends Controller
{
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

    public function store(UploadGalleryImageRequest $request): JsonResponse
    {
        $file = $request->file('image');
        // ADR-019: was hardcoded to 'public' — now driven by
        // config/filesystems.php's gallery_disk (GALLERY_DISK env, not
        // the app-wide FILESYSTEM_DISK default, which this env already
        // sets to 'local' for unrelated reasons and has no public URL).
        $disk = config('filesystems.gallery_disk');
        $path = $file->store('gallery', $disk);

        $image = GalleryImage::query()->create([
            'original_name' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($image, 201);
    }

    public function destroy(GalleryImage $galleryImage): JsonResponse
    {
        Storage::disk($galleryImage->disk)->delete($galleryImage->path);
        $galleryImage->delete();

        return response()->json(null, 204);
    }
}
