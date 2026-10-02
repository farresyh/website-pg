<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\AffiliateFooterSettings;
use App\Models\Game;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-028 + its 2026-08-22 addendum: the admin side of Store Branding /
 * Footer Settings / Platform Settings, and SET-2's bulk markup action.
 */
class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ADR-061: these endpoints resolve the platform storefront via
        // Affiliate::primary(), which fails loud when it is absent.
        $this->primaryAffiliate();
    }

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    private function game(string $slug = 'mobile-legends', bool $isActive = true): Game
    {
        return Game::query()->create(['name' => ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug, 'is_active' => $isActive]);
    }

    private function package(Game $game, array $overrides = []): Package
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'gamevion'],
            ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
        );

        return Package::query()->create(array_merge([
            'game_id' => $game->id,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV'.random_int(100000, 999999),
            'name' => '100 Diamonds',
            'cost_price' => 1000,
            'standard_selling_price' => 1100,
            'markup_percent' => 10,
            'is_active' => true,
        ], $overrides));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/settings')->assertForbidden();
    }

    public function test_index_returns_all_three_sections_creating_defaults_on_first_access(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->getJson('/api/settings');

        $response->assertOk();
        $response->assertJsonStructure(['branding' => ['store_name'], 'footer', 'platform' => ['currency']]);
        $this->assertSame('MYR', $response->json('platform.currency'));
        $this->assertDatabaseCount('affiliate_branding', 1);
        $this->assertDatabaseCount('affiliate_footer_settings', 1);
        $this->assertDatabaseCount('platform_settings', 1);
    }

    public function test_update_branding_persists_fields(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->putJson('/api/settings/branding', [
            'store_name' => 'PekanGame',
            'description' => 'Fast top-ups',
            'support_email' => 'support@pekangame.space',
            'support_phone' => '+60123456789',
            'social_links' => ['facebook' => 'https://facebook.com/krs'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('affiliate_branding', [
            'store_name' => 'PekanGame',
            'support_email' => 'support@pekangame.space',
        ]);
    }

    public function test_uploads_and_removes_the_primary_brand_logo(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));
        $this->actingAsSuperAdmin();
        $primary = $this->primaryAffiliate();

        $response = $this->post('/api/settings/branding/logo', [
            'image' => UploadedFile::fake()->image('logo.png', 900, 300),
        ]);

        $response->assertOk();
        $this->assertStringContainsString('.webp', $response->json('logo_url'));
        Storage::disk(config('filesystems.gallery_disk'))->assertExists("affiliate-logos/{$primary->id}.webp");

        $this->deleteJson('/api/settings/branding/logo')->assertOk()->assertJsonPath('logo_url', null);
        Storage::disk(config('filesystems.gallery_disk'))->assertMissing("affiliate-logos/{$primary->id}.webp");
    }

    public function test_uploads_and_removes_the_primary_brand_favicon(): void
    {
        Storage::fake(config('filesystems.gallery_disk'));
        $this->actingAsSuperAdmin();
        $primary = $this->primaryAffiliate();

        $this->post('/api/settings/branding/favicon', [
            'image' => UploadedFile::fake()->image('not-square.png', 512, 800),
        ])->assertStatus(422)->assertJsonValidationErrors('image');

        $response = $this->post('/api/settings/branding/favicon', [
            'image' => UploadedFile::fake()->image('favicon.png', 512, 512),
        ]);

        $response->assertOk();
        $this->assertStringContainsString('.webp', $response->json('favicon_url'));
        Storage::disk(config('filesystems.gallery_disk'))->assertExists("affiliate-favicons/{$primary->id}.webp");

        $this->deleteJson('/api/settings/branding/favicon')->assertOk()->assertJsonPath('favicon_url', null);
        Storage::disk(config('filesystems.gallery_disk'))->assertMissing("affiliate-favicons/{$primary->id}.webp");
    }

    public function test_update_footer_sanitizes_legal_content_and_keeps_the_store_name_token_raw(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->putJson('/api/settings/footer', [
            'footer_text' => '© 2026 {store_name}',
            'terms_content' => '<p>Welcome to <b>{store_name}</b></p><script>alert(1)</script>',
        ]);

        $response->assertOk();
        $stored = AffiliateFooterSettings::query()->first();
        $this->assertStringContainsString('{store_name}', $stored->terms_content);
        $this->assertStringNotContainsString('<script>', $stored->terms_content);
        $this->assertStringContainsString('<b>', $stored->terms_content);
    }

    public function test_update_footer_rejects_more_than_four_games(): void
    {
        $this->actingAsSuperAdmin();
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->game("game-{$i}")->id;
        }

        $this->putJson('/api/settings/footer', ['footer_game_ids' => $ids])->assertStatus(422);
    }

    public function test_update_footer_rejects_an_inactive_game(): void
    {
        $this->actingAsSuperAdmin();
        $inactive = $this->game('inactive-game', false);

        $this->putJson('/api/settings/footer', ['footer_game_ids' => [$inactive->id]])->assertStatus(422);
    }

    public function test_update_footer_accepts_up_to_four_active_games_in_order(): void
    {
        $this->actingAsSuperAdmin();
        $ids = [];
        foreach (range(1, 4) as $i) {
            $ids[] = $this->game("game-{$i}")->id;
        }
        $ids = array_reverse($ids);

        $response = $this->putJson('/api/settings/footer', ['footer_game_ids' => $ids]);

        $response->assertOk();
        $this->assertSame($ids, AffiliateFooterSettings::query()->first()->footer_game_ids);
    }

    public function test_update_platform_persists_maintenance_and_telegram_fields(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->putJson('/api/settings/platform', [
            'maintenance_mode' => true,
            'maintenance_message' => 'Back soon',
            'vip_spend_threshold_sen' => 500000,
            'telegram_notifications_enabled' => true,
            'telegram_bot_token' => 'abc123',
            'telegram_chat_id' => '-100999',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('platform_settings', [
            'maintenance_mode' => true,
            'vip_spend_threshold_sen' => 500000,
            'telegram_notifications_enabled' => true,
        ]);
    }

    public function test_update_platform_persists_vip_spend_threshold(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->putJson('/api/settings/platform', [
            'maintenance_mode' => false,
            'vip_spend_threshold_sen' => 750000,
            'telegram_notifications_enabled' => false,
        ]);

        $response->assertOk();
        $this->assertSame(750000, PlatformSettings::current()->vip_spend_threshold_sen);
    }

    public function test_bulk_markup_updates_every_package_active_or_not_and_logs_a_price_change(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game();
        $active = $this->package($game);
        $inactive = $this->package($game, ['name' => 'Inactive Pack', 'is_active' => false]);

        $response = $this->postJson('/api/settings/platform/bulk-markup', ['markup_percent' => 20]);

        $response->assertOk();
        $this->assertSame(2, $response->json('packages_updated'));
        $active->refresh();
        $this->assertEquals(20, $active->markup_percent);
        $this->assertSame(1200, $active->standard_selling_price);
        $inactive->refresh();
        $this->assertEquals(20, $inactive->markup_percent);
        $this->assertSame(1200, $inactive->standard_selling_price);
        $this->assertDatabaseHas('price_change_logs', [
            'package_id' => $active->id,
            'old_standard_selling_price' => 1100,
            'new_standard_selling_price' => 1200,
        ]);
        $this->assertDatabaseHas('price_change_logs', [
            'package_id' => $inactive->id,
            'old_standard_selling_price' => 1100,
            'new_standard_selling_price' => 1200,
        ]);
    }

    private function combo(Game $game, array $components, array $overrides = []): Package
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson("/api/games/{$game->id}/packages/combo", [
            'name' => 'Combo Pack',
            'components' => $components,
        ])->assertCreated();

        $combo = Package::query()->findOrFail($response->json('id'));

        if ($overrides !== []) {
            $this->patchJson("/api/packages/{$combo->id}/combo-override", $overrides)->assertOk();
            $combo->refresh();
        }

        return $combo;
    }

    public function test_bulk_markup_recomputes_a_sum_of_components_combo_from_its_updated_components(): void
    {
        $game = $this->game();
        $a = $this->package($game, ['name' => 'Part A', 'denomination' => 100, 'cost_price' => 1000, 'standard_selling_price' => 1100, 'markup_percent' => 10]);
        $b = $this->package($game, ['name' => 'Part B', 'denomination' => 100, 'cost_price' => 1000, 'standard_selling_price' => 1100, 'markup_percent' => 10]);
        $combo = $this->combo($game, [
            ['package_id' => $a->id, 'quantity' => 1],
            ['package_id' => $b->id, 'quantity' => 1],
        ]);
        $this->assertSame(2200, $combo->standard_selling_price);

        $this->postJson('/api/settings/platform/bulk-markup', ['markup_percent' => 20])->assertOk();

        $combo->refresh();
        // Components become 1200 each (cost 1000 x 1.20) -> combo sum = 2400.
        $this->assertSame(2400, $combo->standard_selling_price);
        $this->assertNull($combo->combo_override_markup_percent);
        $this->assertNull($combo->combo_override_price);
        $this->assertDatabaseHas('price_change_logs', [
            'package_id' => $combo->id,
            'old_standard_selling_price' => 2200,
            'new_standard_selling_price' => 2400,
        ]);
    }

    public function test_bulk_markup_skips_a_combo_with_a_custom_markup_override(): void
    {
        $game = $this->game();
        $a = $this->package($game, ['name' => 'Part A', 'denomination' => 100, 'cost_price' => 1000, 'standard_selling_price' => 1100, 'markup_percent' => 10]);
        $combo = $this->combo($game, [
            ['package_id' => $a->id, 'quantity' => 1],
        ], ['combo_override_markup_percent' => 15]);
        $priceBefore = $combo->standard_selling_price;

        $this->postJson('/api/settings/platform/bulk-markup', ['markup_percent' => 20])->assertOk();

        $combo->refresh();
        $this->assertEquals(15, $combo->combo_override_markup_percent);
        $this->assertSame($priceBefore, $combo->standard_selling_price);
        $this->assertDatabaseMissing('price_change_logs', ['package_id' => $combo->id]);
    }

    public function test_bulk_markup_skips_a_combo_with_a_custom_fixed_price(): void
    {
        $game = $this->game();
        $a = $this->package($game, ['name' => 'Part A', 'denomination' => 100, 'cost_price' => 1000, 'standard_selling_price' => 1100, 'markup_percent' => 10]);
        $combo = $this->combo($game, [
            ['package_id' => $a->id, 'quantity' => 1],
        ], ['combo_override_price' => 1050]);

        $this->postJson('/api/settings/platform/bulk-markup', ['markup_percent' => 20])->assertOk();

        $combo->refresh();
        $this->assertSame(1050, $combo->standard_selling_price);
        $this->assertDatabaseMissing('price_change_logs', ['package_id' => $combo->id]);
    }

    public function test_bulk_markup_recomputes_an_inactive_sum_of_components_combo_too(): void
    {
        $game = $this->game();
        $a = $this->package($game, ['name' => 'Part A', 'denomination' => 100, 'cost_price' => 1000, 'standard_selling_price' => 1100, 'markup_percent' => 10]);
        $combo = $this->combo($game, [
            ['package_id' => $a->id, 'quantity' => 1],
        ]);
        $combo->update(['is_active' => false]);

        $this->postJson('/api/settings/platform/bulk-markup', ['markup_percent' => 20])->assertOk();

        $combo->refresh();
        $this->assertSame(1200, $combo->standard_selling_price);
    }

    public function test_bulk_markup_is_a_no_op_for_a_package_already_at_the_target_markup(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game();
        $this->package($game, ['markup_percent' => 20, 'standard_selling_price' => 1200]);

        $response = $this->postJson('/api/settings/platform/bulk-markup', ['markup_percent' => 20]);

        $response->assertOk();
        $this->assertSame(0, $response->json('packages_updated'));
        $this->assertDatabaseCount('price_change_logs', 0);
    }

    /**
     * ADR-027's 2026-08-29 addendum: member_price_sen is computed live
     * from Package.markup_percent, not a snapshot — a Platform Settings
     * bulk markup change must propagate to it too, same as a single
     * package's own markup edit. Proven via the real public catalog
     * endpoint, not by asserting the DB column alone.
     */
    public function test_bulk_markup_change_is_reflected_in_public_catalog_member_price(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game();
        $this->package($game);

        $this->patchJson('/api/membership-plans/enabled', ['membership_enabled' => true])->assertOk();

        $this->postJson('/api/settings/platform/bulk-markup', ['markup_percent' => 20])->assertOk();

        // Effective markup 20% * (1-0.8) = 4% -> round(1000 * 1.04) = 1040.
        $this->getJson("/api/catalog/games/{$game->slug}/packages")->assertJsonPath('0.member_price_sen', 1040);
    }
}
