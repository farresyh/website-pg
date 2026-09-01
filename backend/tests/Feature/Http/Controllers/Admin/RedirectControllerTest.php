<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Redirect;
use App\Models\Reseller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ADR-029 decision 3/9: admin CRUD over reseller-scoped path redirects. */
class RedirectControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/seo/redirects')->assertForbidden();
    }

    public function test_index_orders_by_hit_count_descending(): void
    {
        $this->actingAsSuperAdmin();
        $reseller = $this->primaryReseller();
        Redirect::query()->create(['reseller_id' => $reseller->id, 'from_path' => '/low', 'to_path' => '/a', 'status_code' => 301, 'hit_count' => 2]);
        Redirect::query()->create(['reseller_id' => $reseller->id, 'from_path' => '/high', 'to_path' => '/b', 'status_code' => 301, 'hit_count' => 9]);

        $response = $this->getJson('/api/seo/redirects');

        $response->assertOk();
        $this->assertSame('/high', $response->json('0.from_path'));
    }

    public function test_store_creates_a_redirect_scoped_to_the_platform_owner(): void
    {
        $this->actingAsSuperAdmin();
        $reseller = $this->primaryReseller();

        $response = $this->postJson('/api/seo/redirects', [
            'from_path' => '/old-page',
            'to_path' => '/new-page',
            'status_code' => 301,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('redirects', [
            'reseller_id' => $reseller->id,
            'from_path' => '/old-page',
            'to_path' => '/new-page',
            'status_code' => 301,
        ]);
    }

    public function test_store_rejects_a_from_path_not_starting_with_a_slash(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryReseller();

        $this->postJson('/api/seo/redirects', ['from_path' => 'old-page', 'to_path' => '/new', 'status_code' => 301])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_path');
    }

    public function test_store_rejects_a_status_code_outside_301_or_302(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryReseller();

        $this->postJson('/api/seo/redirects', ['from_path' => '/old', 'to_path' => '/new', 'status_code' => 404])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status_code');
    }

    public function test_store_rejects_a_duplicate_from_path_for_the_same_reseller(): void
    {
        $this->actingAsSuperAdmin();
        $reseller = $this->primaryReseller();
        Redirect::query()->create(['reseller_id' => $reseller->id, 'from_path' => '/old', 'to_path' => '/new', 'status_code' => 301]);

        $this->postJson('/api/seo/redirects', ['from_path' => '/old', 'to_path' => '/elsewhere', 'status_code' => 302])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_path');
    }

    public function test_store_invalidates_the_public_seo_cache(): void
    {
        $this->actingAsSuperAdmin();
        $reseller = $this->primaryReseller();
        Cache::put("catalog.public.redirects.{$reseller->id}", ['stale' => true], 60);

        $this->postJson('/api/seo/redirects', ['from_path' => '/old', 'to_path' => '/new', 'status_code' => 301])->assertCreated();

        $this->assertFalse(Cache::has("catalog.public.redirects.{$reseller->id}"));
    }

    public function test_update_allows_keeping_the_same_from_path(): void
    {
        $this->actingAsSuperAdmin();
        $reseller = $this->primaryReseller();
        $redirect = Redirect::query()->create(['reseller_id' => $reseller->id, 'from_path' => '/old', 'to_path' => '/new', 'status_code' => 301]);

        $response = $this->putJson("/api/seo/redirects/{$redirect->id}", [
            'from_path' => '/old',
            'to_path' => '/newer',
            'status_code' => 302,
        ]);

        $response->assertOk();
        $this->assertSame('/newer', $response->json('to_path'));
        $this->assertSame(302, $response->json('status_code'));
    }

    public function test_destroy_removes_the_row_and_invalidates_cache(): void
    {
        $this->actingAsSuperAdmin();
        $reseller = $this->primaryReseller();
        $redirect = Redirect::query()->create(['reseller_id' => $reseller->id, 'from_path' => '/old', 'to_path' => '/new', 'status_code' => 301]);
        Cache::put("catalog.public.redirects.{$reseller->id}", ['stale' => true], 60);

        $this->deleteJson("/api/seo/redirects/{$redirect->id}")->assertNoContent();

        $this->assertDatabaseMissing('redirects', ['id' => $redirect->id]);
        $this->assertFalse(Cache::has("catalog.public.redirects.{$reseller->id}"));
    }
}
