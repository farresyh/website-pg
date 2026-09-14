<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\AffiliateBranding;
use App\Models\GalleryImage;
use App\Models\Game;
use App\Models\HeroSlide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GalleryImageControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/gallery/images')->assertUnauthorized();
    }

    public function test_store_requires_authentication(): void
    {
        Storage::fake('public');

        $this->postJson('/api/gallery/images', [
            'image' => UploadedFile::fake()->image('cover.png'),
        ])->assertUnauthorized();
    }

    public function test_store_uploads_an_image_and_records_the_uploader(): void
    {
        Storage::fake('public');
        $admin = $this->actingAsAdmin();

        $response = $this->postJson('/api/gallery/images', [
            'image' => UploadedFile::fake()->image('mlbb-cover.png', 800, 600),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('original_name', 'mlbb-cover.png');
        $response->assertJsonPath('uploaded_by', $admin->id);
        $this->assertNotNull($response->json('url'));

        $image = GalleryImage::query()->firstOrFail();
        $this->assertSame('public', $image->disk);
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_store_rejects_a_non_image_file(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $response = $this->postJson('/api/gallery/images', [
            'image' => UploadedFile::fake()->create('malware.exe', 10),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('image');
        $this->assertSame(0, GalleryImage::query()->count());
    }

    public function test_store_rejects_a_file_over_the_size_limit(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $response = $this->postJson('/api/gallery/images', [
            // fake()->image()'s size is in kilobytes, matching the 5120 (5MB) rule.
            'image' => UploadedFile::fake()->image('huge.png')->size(6000),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('image');
    }

    public function test_index_lists_uploaded_images_newest_first(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $this->postJson('/api/gallery/images', ['image' => UploadedFile::fake()->image('first.png')])->assertCreated();
        $this->postJson('/api/gallery/images', ['image' => UploadedFile::fake()->image('second.png')])->assertCreated();

        $response = $this->getJson('/api/gallery/images');

        $response->assertOk();
        $this->assertSame(
            ['second.png', 'first.png'],
            collect($response->json('data'))->pluck('original_name')->all(),
        );
    }

    public function test_index_filters_by_search(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $this->postJson('/api/gallery/images', ['image' => UploadedFile::fake()->image('mlbb-diamonds.png')])->assertCreated();
        $this->postJson('/api/gallery/images', ['image' => UploadedFile::fake()->image('freefire-diamonds.png')])->assertCreated();

        $response = $this->getJson('/api/gallery/images?search=mlbb');

        $response->assertOk();
        $this->assertSame(['mlbb-diamonds.png'], collect($response->json('data'))->pluck('original_name')->all());
    }

    public function test_destroy_deletes_the_file_and_the_row(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $upload = $this->postJson('/api/gallery/images', ['image' => UploadedFile::fake()->image('gone.png')]);
        $image = GalleryImage::query()->firstOrFail();

        $response = $this->deleteJson("/api/gallery/images/{$image->id}");

        $response->assertNoContent();
        $this->assertSame(0, GalleryImage::query()->count());
        Storage::disk('public')->assertMissing($image->path);
    }

    /** ADR-095: reuses ImageIngestService, same as logo/hero. */
    public function test_store_re_encodes_to_webp_and_caps_the_longest_edge_at_2000px(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        // 3000x1500 source, capped at 2000 → 2000x1000.
        $response = $this->postJson('/api/gallery/images', [
            'image' => UploadedFile::fake()->image('huge-banner.jpg', 3000, 1500),
        ]);

        $response->assertCreated();
        $image = GalleryImage::query()->firstOrFail();
        $this->assertStringEndsWith('.webp', $image->path);
        $this->assertSame('image/webp', $image->mime_type);

        $bytes = Storage::disk('public')->get($image->path);
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));

        $decoded = ImageManager::gd()->read($bytes);
        $this->assertSame(2000, $decoded->width());
        $this->assertSame(1000, $decoded->height());
    }

    public function test_store_rejects_a_file_over_the_dimension_limit(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $response = $this->postJson('/api/gallery/images', [
            'image' => UploadedFile::fake()->image('too-big.png', 6000, 6000),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('image');
        $this->assertSame(0, GalleryImage::query()->count());
    }

    public function test_references_is_empty_when_nothing_uses_the_image(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $this->postJson('/api/gallery/images', ['image' => UploadedFile::fake()->image('unused.png')])->assertCreated();
        $image = GalleryImage::query()->firstOrFail();

        $response = $this->getJson("/api/gallery/images/{$image->id}/references");

        $response->assertOk();
        $this->assertSame([], $response->json('references'));
    }

    public function test_references_lists_every_real_consumer_of_the_image(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $this->postJson('/api/gallery/images', ['image' => UploadedFile::fake()->image('shared.png')])->assertCreated();
        $image = GalleryImage::query()->firstOrFail();

        Game::query()->create([
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'image_url' => $image->url,
        ]);
        HeroSlide::query()->create([
            'title' => 'Top Up Sekejap',
            'primary_cta_label' => 'Beli Sekarang',
            'primary_cta_href' => '/order/mobile-legends',
            'image_url' => $image->url,
        ]);
        $affiliate = $this->primaryAffiliate();
        AffiliateBranding::query()->create([
            'affiliate_id' => $affiliate->id,
            'store_name' => 'PekanGame',
            // AffiliateBranding stores a disk PATH, not a URL — the other
            // two consumers above store a pasted absolute URL.
            'logo_path' => $image->path,
        ]);

        $response = $this->getJson("/api/gallery/images/{$image->id}/references");

        $response->assertOk();
        $this->assertSame(
            [
                'Game — Mobile Legends (image_url)',
                'Hero Slide — Top Up Sekejap',
                'Affiliate Branding — PekanGame (logo)',
            ],
            $response->json('references'),
        );
    }
}
