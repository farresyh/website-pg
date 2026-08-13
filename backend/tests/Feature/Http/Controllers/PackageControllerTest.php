<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PackageControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function package(array $overrides = []): Package
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);

        return Package::query()->create(array_merge([
            'game_id' => $game->id,
            'name' => '100 Diamonds',
            'cost_price' => 421,
            'reseller_cost_price' => 500,
            'markup_percent' => 18.76,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'A',
        ], $overrides));
    }

    public function test_update_requires_authentication(): void
    {
        $package = $this->package();

        $this->putJson("/api/packages/{$package->id}", [])->assertUnauthorized();
    }

    public function test_update_renames_the_package(): void
    {
        $package = $this->package();
        $this->actingAsAdmin();

        $response = $this->putJson("/api/packages/{$package->id}", [
            'name' => '100 Diamonds (Promo)',
        ]);

        $response->assertOk();
        $this->assertSame('100 Diamonds (Promo)', $package->refresh()->name);
    }

    /**
     * `update` never touches markup/reseller_cost_price/is_active —
     * those are dedicated endpoints (updateMarkup/updateStatus),
     * matching the legacy reference system's per-row inline actions.
     */
    public function test_update_does_not_change_markup_or_status_even_if_sent(): void
    {
        $package = $this->package(['reseller_cost_price' => 500, 'markup_percent' => 18.76, 'is_active' => true]);
        $this->actingAsAdmin();

        $this->putJson("/api/packages/{$package->id}", [
            'name' => '100 Diamonds',
            'reseller_cost_price' => 999,
            'markup_percent' => 99,
            'is_active' => false,
        ])->assertOk();

        $package->refresh();
        $this->assertSame(500, $package->reseller_cost_price);
        $this->assertSame('18.76', (string) $package->markup_percent);
        $this->assertTrue($package->is_active);
    }

    /**
     * `cost_price` only ever comes from the supplier sync — confirmed
     * founder decision (docs/prd.md §14) — this endpoint doesn't even
     * accept it as input, so it's untouched regardless of what's sent.
     */
    public function test_update_never_changes_cost_price_even_if_sent(): void
    {
        $package = $this->package(['cost_price' => 421]);
        $this->actingAsAdmin();

        $this->putJson("/api/packages/{$package->id}", [
            'name' => '100 Diamonds',
            'cost_price' => 999999,
        ])->assertOk();

        $this->assertSame(421, $package->refresh()->cost_price);
    }

    /**
     * Founder revision, 2026-07-25: markup is set per-package as a %,
     * and `reseller_cost_price` is recomputed and stored from it —
     * never typed directly.
     */
    public function test_update_markup_recomputes_and_stores_reseller_cost_price(): void
    {
        $package = $this->package(['cost_price' => 421]);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/packages/{$package->id}/markup", [
            'markup_percent' => 15,
        ]);

        $response->assertOk();
        $package->refresh();
        $this->assertSame('15.00', (string) $package->markup_percent);
        $this->assertSame(484, $package->reseller_cost_price); // round(421 * 1.15)
    }

    public function test_update_markup_rejects_a_negative_value(): void
    {
        $package = $this->package();
        $this->actingAsAdmin();

        $this->patchJson("/api/packages/{$package->id}/markup", ['markup_percent' => -5])
            ->assertUnprocessable();
    }

    public function test_update_markup_rejects_an_unbounded_value(): void
    {
        $package = $this->package();
        $this->actingAsAdmin();

        $this->patchJson("/api/packages/{$package->id}/markup", ['markup_percent' => 1001])
            ->assertUnprocessable();
    }

    public function test_update_status_toggles_active_inline(): void
    {
        $package = $this->package(['is_active' => true]);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/packages/{$package->id}/status", ['is_active' => false]);

        $response->assertOk();
        $this->assertFalse($package->refresh()->is_active);
    }

    public function test_destroy_removes_the_package(): void
    {
        $package = $this->package();
        $this->actingAsAdmin();

        $response = $this->deleteJson("/api/packages/{$package->id}");

        $response->assertNoContent();
        $this->assertSame(0, Package::query()->count());
    }
}
