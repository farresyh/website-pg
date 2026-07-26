<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\HeroSlide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HeroSlideControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'eyebrow' => 'Top Up Made Easy',
            'title' => 'Top up your favorite games in seconds',
            'description' => 'Pick your game, place your order, and pay your way.',
            'image_url' => null,
            'price_from_sen' => null,
            'primary_cta_label' => 'Find Games',
            'primary_cta_href' => '#popular-picks',
            'secondary_cta_label' => 'Track Order',
            'secondary_cta_href' => '/track-order',
            'is_active' => true,
            'sort_order' => 0,
            'starts_at' => null,
            'ends_at' => null,
        ], $overrides);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/hero-slides')->assertUnauthorized();
    }

    public function test_index_lists_slides_ordered_by_sort_order(): void
    {
        HeroSlide::query()->create($this->payload(['title' => 'Second', 'sort_order' => 2]));
        HeroSlide::query()->create($this->payload(['title' => 'First', 'sort_order' => 1]));
        $this->actingAsAdmin();

        $response = $this->getJson('/api/hero-slides');

        $response->assertOk();
        $this->assertSame(['First', 'Second'], collect($response->json())->pluck('title')->all());
    }

    public function test_store_creates_a_slide(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/hero-slides', $this->payload(['title' => 'Weekend Bonus UC']));

        $response->assertCreated();
        $this->assertSame(1, HeroSlide::query()->count());
        $this->assertSame('Weekend Bonus UC', HeroSlide::query()->first()->title);
    }

    public function test_store_requires_a_title_and_primary_cta(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/hero-slides', $this->payload(['title' => '', 'primary_cta_label' => '']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['title', 'primary_cta_label']);
    }

    public function test_update_edits_the_slides_fields(): void
    {
        $slide = HeroSlide::query()->create($this->payload());
        $this->actingAsAdmin();

        $response = $this->putJson("/api/hero-slides/{$slide->id}", $this->payload([
            'title' => 'Updated title',
            'image_url' => 'https://cdn.example.com/hero.png',
            'price_from_sen' => 400,
        ]));

        $response->assertOk();
        $slide->refresh();
        $this->assertSame('Updated title', $slide->title);
        $this->assertSame('https://cdn.example.com/hero.png', $slide->image_url);
        $this->assertSame(400, $slide->price_from_sen);
    }

    public function test_update_status_toggles_active_flag(): void
    {
        $slide = HeroSlide::query()->create($this->payload(['is_active' => true]));
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/hero-slides/{$slide->id}/status", ['is_active' => false]);

        $response->assertOk();
        $this->assertFalse($slide->refresh()->is_active);
    }

    public function test_destroy_deletes_the_slide(): void
    {
        $slide = HeroSlide::query()->create($this->payload());
        $this->actingAsAdmin();

        $response = $this->deleteJson("/api/hero-slides/{$slide->id}");

        $response->assertNoContent();
        $this->assertSame(0, HeroSlide::query()->count());
    }

    /**
     * ADR-014 discipline extended here too: the public listing is
     * cached (see HeroSlideControllerTest [public]), and every admin
     * write must invalidate it — proven end-to-end via the two real
     * endpoints together in that test file, not by calling the cache
     * helper directly.
     */
    public function test_store_invalidates_the_public_cache(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/api/catalog/hero-slides')->assertJsonCount(0);

        $this->postJson('/api/hero-slides', $this->payload())->assertCreated();

        $this->getJson('/api/catalog/hero-slides')->assertJsonCount(1);
    }
}
