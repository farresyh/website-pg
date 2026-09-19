<?php

namespace Tests\Feature\Http\Controllers\Affiliate\Storefront;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\AffiliateUser;
use App\Models\HeroSlide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** ADR-060 PR-6 — the Hero tab + its per-brand storefront resolution. */
class HeroSlideControllerTest extends TestCase
{
    use RefreshDatabase;

    private function affiliate(array $attributes = []): Affiliate
    {
        return Affiliate::query()->create(array_merge([
            'business_name' => 'Acme Resell',
            'email' => 'owner+'.uniqid().'@acme.test',
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

    public function test_create_edit_and_delete_a_slide(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));
        $affiliate = $this->affiliate();
        $token = $this->tokenFor($affiliate);

        $created = $this->withToken($token)->post('/api/affiliate/storefront/hero-slides', [
            'title' => 'Big Sale',
            'primary_cta_label' => 'Shop now',
            'primary_cta_href' => '/order/mlbb',
            'is_active' => '1',
            'sort_order' => '0',
            'image' => UploadedFile::fake()->image('hero.jpg', 1200, 600),
        ])->assertCreated()->json();

        $this->assertStringContainsString('.webp', $created['image_url']);
        $this->assertDatabaseHas('hero_slides', ['id' => $created['id'], 'affiliate_id' => $affiliate->id]);

        $this->withToken($token)->put("/api/affiliate/storefront/hero-slides/{$created['id']}", [
            'title' => 'Bigger Sale',
            'primary_cta_label' => 'Shop now',
            'primary_cta_href' => '/order/mlbb',
            'is_active' => '1',
            'sort_order' => '1',
        ])->assertOk()->assertJsonPath('title', 'Bigger Sale');

        $this->withToken($token)->deleteJson("/api/affiliate/storefront/hero-slides/{$created['id']}")
            ->assertNoContent();
        $this->assertDatabaseCount('hero_slides', 0);
    }

    /** 2026-09-19 addendum: image is already required on create, so an asset-only slide just omits title/CTA. */
    public function test_create_an_asset_only_slide_with_no_title_or_cta(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));
        $affiliate = $this->affiliate();

        $created = $this->withToken($this->tokenFor($affiliate))->post('/api/affiliate/storefront/hero-slides', [
            'is_active' => '1',
            'sort_order' => '0',
            'image' => UploadedFile::fake()->image('hero.jpg', 1200, 600),
        ])->assertCreated()->json();

        $this->assertNull($created['title']);
        $this->assertNull($created['primary_cta_label']);
    }

    /** 2026-09-19 addendum: label and href must travel together — never a dead half-button. */
    public function test_store_rejects_a_cta_label_without_a_href(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));
        $affiliate = $this->affiliate();

        $this->withToken($this->tokenFor($affiliate))->post('/api/affiliate/storefront/hero-slides', [
            'title' => 'Big Sale',
            'primary_cta_label' => 'Shop now',
            'is_active' => '1',
            'sort_order' => '0',
            'image' => UploadedFile::fake()->image('hero.jpg', 1200, 600),
        ])->assertUnprocessable()->assertJsonValidationErrors(['primary_cta_href']);
    }

    public function test_the_slide_cap_is_enforced(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));
        $affiliate = $this->affiliate();
        foreach (range(1, 6) as $i) {
            HeroSlide::query()->create([
                'affiliate_id' => $affiliate->id,
                'title' => "Slide {$i}",
                'primary_cta_label' => 'Go', 'primary_cta_href' => '/x',
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }

        $this->withToken($this->tokenFor($affiliate))->post('/api/affiliate/storefront/hero-slides', [
            'title' => 'One too many',
            'primary_cta_label' => 'Go',
            'primary_cta_href' => '/x',
            'is_active' => '1',
            'sort_order' => '7',
            'image' => UploadedFile::fake()->image('hero.jpg'),
        ])->assertStatus(422);
    }

    public function test_an_affiliate_cannot_edit_another_brands_slide(): void
    {
        $a = $this->affiliate();
        $b = $this->affiliate(['business_name' => 'Beta']);
        $slide = HeroSlide::query()->create([
            'affiliate_id' => $b->id,
            'title' => 'Beta slide',
            'primary_cta_label' => 'Go', 'primary_cta_href' => '/x',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->withToken($this->tokenFor($a))
            ->putJson("/api/affiliate/storefront/hero-slides/{$slide->id}", [
                'title' => 'Hijacked', 'primary_cta_label' => 'Go', 'primary_cta_href' => '/x', 'is_active' => true, 'sort_order' => 0,
            ])
            ->assertNotFound();
    }

    public function test_storefront_serves_a_brands_own_slides_then_falls_back_to_global(): void
    {
        $this->primaryAffiliate();
        $brand = $this->affiliate();
        AffiliateDomain::query()->create([
            'affiliate_id' => $brand->id,
            'hostname' => 'shop.acme.test',
            'status' => 'active',
        ]);

        HeroSlide::query()->create([
            'affiliate_id' => null, 'title' => 'Global slide', 'primary_cta_label' => 'Go', 'primary_cta_href' => '/x', 'is_active' => true, 'sort_order' => 0,
        ]);

        // No own slides yet → the brand storefront shows the global set.
        $this->getJson('/api/catalog/hero-slides', ['X-Storefront-Host' => 'shop.acme.test'])
            ->assertOk()
            ->assertJsonPath('0.title', 'Global slide');

        HeroSlide::query()->create([
            'affiliate_id' => $brand->id, 'title' => 'Acme slide', 'primary_cta_label' => 'Go', 'primary_cta_href' => '/x', 'is_active' => true, 'sort_order' => 0,
        ]);
        Cache::flush(); // the slide was seeded directly, not through the portal

        // Now it has its own → the global set is no longer shown.
        $titles = $this->getJson('/api/catalog/hero-slides', ['X-Storefront-Host' => 'shop.acme.test'])->assertOk()->json('*.title');
        $this->assertSame(['Acme slide'], $titles);
    }
}
