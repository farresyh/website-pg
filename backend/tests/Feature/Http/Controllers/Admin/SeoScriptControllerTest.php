<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Reseller;
use App\Models\SeoScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ADR-029 addendum 2 decision 13: admin CRUD over head/body_end scripts. */
class SeoScriptControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/seo/scripts')->assertForbidden();
    }

    public function test_store_creates_a_global_script(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/seo/scripts', [
            'name' => 'Google Analytics',
            'location' => 'head',
            'code' => '<script>ga("send")</script>',
            'priority' => 1,
            'is_active' => true,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('seo_scripts', ['name' => 'Google Analytics', 'reseller_id' => null]);
    }

    public function test_store_rejects_an_invalid_location(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/seo/scripts', ['name' => 'Bad', 'location' => 'sidebar', 'code' => '<script></script>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location');
    }

    public function test_store_rejects_unbalanced_script_tags(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/seo/scripts', [
            'name' => 'Broken',
            'location' => 'head',
            'code' => '<script>ga("send")',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_store_accepts_balanced_script_tags_with_no_scripts_at_all(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/seo/scripts', [
            'name' => 'No script tag',
            'location' => 'body_end',
            'code' => '<div>tracking pixel</div>',
        ])->assertCreated();
    }

    public function test_store_invalidates_the_public_seo_cache(): void
    {
        $this->actingAsSuperAdmin();
        $reseller = Reseller::platformOwner();
        Cache::put("catalog.public.seo_scripts.{$reseller->id}", ['stale' => true], 60);

        $this->postJson('/api/seo/scripts', ['name' => 'GA', 'location' => 'head', 'code' => '<script>x()</script>'])->assertCreated();

        $this->assertFalse(Cache::has("catalog.public.seo_scripts.{$reseller->id}"));
    }

    public function test_update_persists_new_values(): void
    {
        $this->actingAsSuperAdmin();
        $script = SeoScript::query()->create(['name' => 'Old', 'location' => 'head', 'code' => '<script>a()</script>', 'priority' => 1, 'is_active' => true]);

        $response = $this->putJson("/api/seo/scripts/{$script->id}", [
            'name' => 'Renamed',
            'location' => 'body_end',
            'code' => '<script>b()</script>',
            'is_active' => false,
        ]);

        $response->assertOk();
        $this->assertSame('Renamed', $response->json('name'));
        $this->assertFalse($response->json('is_active'));
    }

    public function test_destroy_removes_the_script(): void
    {
        $this->actingAsSuperAdmin();
        $script = SeoScript::query()->create(['name' => 'ToDelete', 'location' => 'head', 'code' => '<script>a()</script>']);

        $this->deleteJson("/api/seo/scripts/{$script->id}")->assertNoContent();

        $this->assertDatabaseMissing('seo_scripts', ['id' => $script->id]);
    }
}
