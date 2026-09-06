<?php

namespace Tests\Feature\Http\Controllers\Affiliate\Storefront;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** ADR-060 PR-6 — the Branding tab (identity + logo + pixel IDs). */
class BrandingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function affiliate(array $attributes = []): Affiliate
    {
        return Affiliate::query()->create(array_merge([
            'business_name' => 'Acme Resell',
            'email' => 'owner@acme.test',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ], $attributes));
    }

    private function tokenFor(Affiliate $affiliate): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'name' => 'Staff',
            'email' => 'staff+'.uniqid().'@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    public function test_update_saves_identity_fields_and_creates_the_row(): void
    {
        $affiliate = $this->affiliate();

        $this->withToken($this->tokenFor($affiliate))->putJson('/api/affiliate/storefront/branding', [
            'store_name' => 'Acme Games',
            'description' => 'Fast top-ups',
            'support_email' => 'help@acme.test',
            'social_links' => ['facebook' => 'https://fb.com/acme', 'instagram' => ''],
        ])->assertOk()->assertJsonPath('branding.store_name', 'Acme Games');

        $this->assertDatabaseHas('affiliate_branding', [
            'affiliate_id' => $affiliate->id,
            'store_name' => 'Acme Games',
        ]);
        // Empty social entries are dropped, not stored blank.
        $this->assertSame(
            ['facebook' => 'https://fb.com/acme'],
            AffiliateBranding::withoutAffiliateScope()->where('affiliate_id', $affiliate->id)->sole()->social_links,
        );
    }

    public function test_pixel_ids_reject_a_script_breakout_and_store_a_clean_id(): void
    {
        $affiliate = $this->affiliate();
        $token = $this->tokenFor($affiliate);

        $this->withToken($token)->putJson('/api/affiliate/storefront/seo', [
            'fb_pixel_id' => "12345'); evil(); //",
        ])->assertStatus(422)->assertJsonValidationErrors('fb_pixel_id');

        $this->withToken($token)->putJson('/api/affiliate/storefront/seo', [
            'ga_measurement_id' => 'G-ABC123XYZ',
            'fb_pixel_id' => '',
        ])->assertOk()->assertJsonPath('ga_measurement_id', 'G-ABC123XYZ');

        $this->assertDatabaseHas('affiliate_seo_settings', [
            'affiliate_id' => $affiliate->id,
            'ga_measurement_id' => 'G-ABC123XYZ',
            'fb_pixel_id' => null,
        ]);
    }

    public function test_logo_upload_stores_a_webp_and_exposes_a_url(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));
        $affiliate = $this->affiliate();

        $response = $this->withToken($this->tokenFor($affiliate))->post('/api/affiliate/storefront/branding/logo', [
            'image' => UploadedFile::fake()->image('logo.png', 900, 900),
        ]);

        $response->assertOk();
        $this->assertStringContainsString('.webp', $response->json('branding.logo_url'));
        Storage::disk(config('filesystems.gallery_disk'))->assertExists("affiliate-logos/{$affiliate->id}.webp");
        $this->assertDatabaseHas('affiliate_branding', [
            'affiliate_id' => $affiliate->id,
            'logo_path' => "affiliate-logos/{$affiliate->id}.webp",
        ]);
    }

    public function test_logo_upload_rejects_a_non_image(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));
        $affiliate = $this->affiliate();

        $this->withToken($this->tokenFor($affiliate))->post('/api/affiliate/storefront/branding/logo', [
            'image' => UploadedFile::fake()->create('malware.svg', 20, 'image/svg+xml'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_delete_logo_removes_the_file_and_nulls_the_column(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));
        $affiliate = $this->affiliate();
        $token = $this->tokenFor($affiliate);

        $this->withToken($token)->post('/api/affiliate/storefront/branding/logo', [
            'image' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();

        $this->withToken($token)->deleteJson('/api/affiliate/storefront/branding/logo')
            ->assertOk()->assertJsonPath('branding.logo_url', null);

        Storage::disk(config('filesystems.gallery_disk'))->assertMissing("affiliate-logos/{$affiliate->id}.webp");
    }

    public function test_a_deactivated_affiliate_is_read_only(): void
    {
        $affiliate = $this->affiliate(['status' => 'deactivated']);
        $token = $this->tokenFor($affiliate);

        $this->withToken($token)->getJson('/api/affiliate/storefront/branding')
            ->assertOk()->assertJsonPath('writable', false);

        $this->withToken($token)->putJson('/api/affiliate/storefront/branding', ['store_name' => 'x'])
            ->assertStatus(403);
    }

    public function test_one_affiliate_cannot_read_or_write_another_brands_branding(): void
    {
        $a = $this->affiliate(['email' => 'a@acme.test']);
        $b = $this->affiliate(['business_name' => 'Beta', 'email' => 'b@beta.test']);
        AffiliateBranding::query()->create(['affiliate_id' => $b->id, 'store_name' => 'Beta Store']);

        $this->withToken($this->tokenFor($a))->getJson('/api/affiliate/storefront/branding')
            ->assertOk()->assertJsonPath('branding.store_name', 'Acme Resell');

        $this->withToken($this->tokenFor($a))->putJson('/api/affiliate/storefront/branding', ['store_name' => 'Hijack'])
            ->assertOk();

        $this->assertSame('Beta Store', AffiliateBranding::withoutAffiliateScope()->where('affiliate_id', $b->id)->sole()->store_name);
    }
}
