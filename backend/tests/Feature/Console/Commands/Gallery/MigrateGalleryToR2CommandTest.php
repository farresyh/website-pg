<?php

namespace Tests\Feature\Console\Commands\Gallery;

use App\Models\AffiliateBranding;
use App\Models\GalleryImage;
use App\Models\HeroSlide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** ADR-095 decision 6 — the one-time real-file migration, not a permanent old/new-disk split. */
class MigrateGalleryToR2CommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_refuses_to_run_when_gallery_disk_still_equals_the_from_disk(): void
    {
        Config::set('filesystems.gallery_disk', 'public');

        $this->artisan('gallery:migrate-to-r2', ['--from' => 'public'])
            ->assertFailed();
    }

    public function test_migrates_a_gallery_image_file_and_updates_its_disk_column(): void
    {
        Storage::fake('public');
        Storage::fake('target');
        Config::set('filesystems.gallery_disk', 'target');

        Storage::disk('public')->put('gallery/old.webp', 'fake-bytes');
        $image = GalleryImage::query()->create([
            'original_name' => 'old.png',
            'disk' => 'public',
            'path' => 'gallery/old.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 11,
        ]);

        $this->artisan('gallery:migrate-to-r2', ['--from' => 'public'])->assertSuccessful();

        Storage::disk('target')->assertExists('gallery/old.webp');
        $this->assertSame('fake-bytes', Storage::disk('target')->get('gallery/old.webp'));
        $this->assertSame('target', $image->refresh()->disk);
    }

    public function test_dry_run_copies_nothing_and_updates_no_row(): void
    {
        Storage::fake('public');
        Storage::fake('target');
        Config::set('filesystems.gallery_disk', 'target');

        Storage::disk('public')->put('gallery/old.webp', 'fake-bytes');
        $image = GalleryImage::query()->create([
            'original_name' => 'old.png',
            'disk' => 'public',
            'path' => 'gallery/old.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 11,
        ]);

        $this->artisan('gallery:migrate-to-r2', ['--from' => 'public', '--dry-run' => true])->assertSuccessful();

        Storage::disk('target')->assertMissing('gallery/old.webp');
        $this->assertSame('public', $image->refresh()->disk);
    }

    public function test_migrates_affiliate_logo_and_hero_slide_paths_without_touching_their_rows(): void
    {
        Storage::fake('public');
        Storage::fake('target');
        Config::set('filesystems.gallery_disk', 'target');

        Storage::disk('public')->put('affiliate-logos/1.webp', 'logo-bytes');
        Storage::disk('public')->put('affiliate-hero/1/x.webp', 'hero-bytes');

        $affiliate = $this->primaryAffiliate();
        AffiliateBranding::query()->create([
            'affiliate_id' => $affiliate->id,
            'store_name' => 'PekanGame',
            'logo_path' => 'affiliate-logos/1.webp',
        ]);
        HeroSlide::query()->create([
            'title' => 'Slide',
            'primary_cta_label' => 'Beli',
            'primary_cta_href' => '/order',
            'image_path' => 'affiliate-hero/1/x.webp',
        ]);

        $this->artisan('gallery:migrate-to-r2', ['--from' => 'public'])->assertSuccessful();

        Storage::disk('target')->assertExists('affiliate-logos/1.webp');
        Storage::disk('target')->assertExists('affiliate-hero/1/x.webp');
    }

    public function test_skips_a_row_whose_file_is_missing_on_the_source_disk_without_failing(): void
    {
        Storage::fake('public');
        Storage::fake('target');
        Config::set('filesystems.gallery_disk', 'target');

        GalleryImage::query()->create([
            'original_name' => 'ghost.png',
            'disk' => 'public',
            'path' => 'gallery/ghost.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 0,
        ]);

        $this->artisan('gallery:migrate-to-r2', ['--from' => 'public'])->assertSuccessful();
    }
}
