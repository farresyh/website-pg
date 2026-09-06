<?php

namespace Tests\Feature\Services\Media;

use App\Services\Media\ImageIngestService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/** ADR-060 PR-6 — the shared logo/hero upload seam. */
class ImageIngestServiceTest extends TestCase
{
    private function service(): ImageIngestService
    {
        return $this->app->make(ImageIngestService::class);
    }

    public function test_it_re_encodes_to_webp_on_the_gallery_disk(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));

        $path = $this->service()->ingest(
            UploadedFile::fake()->image('logo.png', 400, 400),
            'affiliate-logos/9.webp',
            512,
        );

        $this->assertSame('affiliate-logos/9.webp', $path);
        Storage::disk(config('filesystems.gallery_disk'))->assertExists($path);

        $bytes = Storage::disk(config('filesystems.gallery_disk'))->get($path);
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
    }

    public function test_it_caps_the_longest_edge_but_never_upscales(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));
        $manager = ImageManager::gd();

        // A 2000x1000 source, capped at 800 → 800x400.
        $wide = $this->service()->ingest(UploadedFile::fake()->image('wide.jpg', 2000, 1000), 'x/wide.webp', 800);
        $img = $manager->read(Storage::disk(config('filesystems.gallery_disk'))->get($wide));
        $this->assertSame(800, $img->width());
        $this->assertSame(400, $img->height());

        // A 300x300 source, cap 800 → unchanged (no upscale).
        $small = $this->service()->ingest(UploadedFile::fake()->image('small.jpg', 300, 300), 'x/small.webp', 800);
        $img = $manager->read(Storage::disk(config('filesystems.gallery_disk'))->get($small));
        $this->assertSame(300, $img->width());
    }

    public function test_delete_is_a_safe_no_op_on_a_missing_path(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));

        $this->service()->delete(null);
        $this->service()->delete('');
        $this->service()->delete('nope/gone.webp');

        $this->expectNotToPerformAssertions();
    }
}
