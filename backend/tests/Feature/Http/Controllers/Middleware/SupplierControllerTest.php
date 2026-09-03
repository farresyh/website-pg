<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\DeactivationLog;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => [],
            'currency' => 'MYR',
        ], $overrides));
    }

    private function fakeAdapter(bool $success = true, array $data = ['balance' => 5000], string $errorCode = 'SERVER_ERROR'): SupplierAdapter
    {
        return new class($success, $data, $errorCode) implements SupplierAdapter
        {
            public function __construct(private bool $success, private array $data, private string $errorCode) {}

            public function checkBalance(): SupplierResponse
            {
                return $this->success
                    ? SupplierResponse::success($this->data)
                    : SupplierResponse::failure($this->errorCode, 'supplier down', $this->errorCode === 'SERVER_ERROR');
            }

            public function listProducts(): SupplierResponse
            {
                return SupplierResponse::success([]);
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                return SupplierResponse::success([]);
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                return SupplierResponse::success([]);
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used');
            }
        };
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/middleware/suppliers')->assertForbidden();
    }

    public function test_index_never_leaks_api_config_and_reports_status(): void
    {
        $this->actingAsAdmin();
        $this->supplier(['api_config' => ['base_url' => 'https://api.gamevion.com', 'bearer_token' => 'secret-value', 'api_key' => 'secret-key', 'sandbox' => true]]);

        $response = $this->getJson('/api/middleware/suppliers')->assertOk();

        $response->assertJsonMissing(['api_config']);
        $body = $response->json()[0];
        $this->assertArrayNotHasKey('api_config', $body);
        $this->assertTrue($body['has_credentials']);
        $this->assertTrue($body['is_fully_configured']);
        $this->assertSame(['bearer_token', 'api_key'], $body['configured_secret_keys']);
        $this->assertSame('closed', $body['circuit_state']);
        $this->assertSame(['packages' => 0, 'supplier_products' => 0, 'orders' => 0], $body['reference_counts']);
        $this->assertStringNotContainsString('secret-value', $response->getContent());

        // ADR-046 decision 3: non-secret keys (base_url/sandbox) are
        // safe to expose so the Edit form can pre-fill them. ADR-069
        // decision 13 adds the optional low_balance_threshold (null
        // until set).
        $this->assertSame(
            ['base_url' => 'https://api.gamevion.com', 'sandbox' => true, 'low_balance_threshold' => null],
            $body['visible_config'],
        );

        // ADR-046 addendum: the card's Sandbox/Production badge.
        $this->assertTrue($body['is_sandbox']);
    }

    /**
     * Regression test, same 2026-08-28 finding as the refresh-balance
     * guard fix: has_credentials alone used to be what the "Configured"
     * badge read, which meant a supplier with only base_url saved
     * looked finished. is_fully_configured is the field that should
     * actually gate that badge, and configured_secret_keys must report
     * only the secret that's genuinely set, not every secret field.
     */
    public function test_index_reports_partial_configuration_accurately(): void
    {
        $this->actingAsAdmin();
        $this->supplier(['api_config' => ['base_url' => 'https://api.gamevion.com', 'bearer_token' => 'secret-value']]);

        $body = $this->getJson('/api/middleware/suppliers')->assertOk()->json()[0];

        $this->assertTrue($body['has_credentials']);
        $this->assertFalse($body['is_fully_configured']);
        $this->assertSame(['bearer_token'], $body['configured_secret_keys']);
    }

    public function test_available_slugs_lists_registered_adapters(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/suppliers/available-slugs')->assertOk();

        $this->assertContains('gamevion', $response->json());
        $this->assertContains('digiflazz', $response->json());
    }

    public function test_store_rejects_a_slug_with_no_registered_adapter(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/middleware/suppliers', [
            'name' => 'Made Up Supplier',
            'slug' => 'made-up-supplier',
            'currency' => 'MYR',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_store_accepts_a_registered_adapter_slug(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/middleware/suppliers', [
            'name' => 'Digiflazz',
            'slug' => 'digiflazz',
            'currency' => 'IDR',
        ])->assertCreated();

        $this->assertDatabaseHas('suppliers', ['slug' => 'digiflazz']);
    }

    public function test_update_status_toggles_is_active(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier(['is_active' => false]);

        $this->patchJson("/api/middleware/suppliers/{$supplier->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('is_active', true);
    }

    public function test_update_merges_api_config_instead_of_replacing_it(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier([
            'api_config' => ['base_url' => 'https://api.gamevion.com', 'bearer_token' => 'existing-secret', 'api_key' => 'existing-key', 'sandbox' => false],
        ]);

        // Frontend never re-sends a secret it was never shown (SUPP-5) —
        // only the visible base_url/sandbox fields are resubmitted.
        $this->putJson("/api/middleware/suppliers/{$supplier->id}", [
            'api_config' => ['base_url' => 'https://api.gamevion.com/v2', 'sandbox' => true],
        ])->assertOk();

        $fresh = $supplier->fresh();
        $this->assertSame('https://api.gamevion.com/v2', $fresh->api_config['base_url']);
        $this->assertTrue($fresh->api_config['sandbox']);
        $this->assertSame('existing-secret', $fresh->api_config['bearer_token'], 'a secret not resubmitted must survive the update untouched');
        $this->assertSame('existing-key', $fresh->api_config['api_key']);
    }

    /**
     * ADR-067 decision 2: the edit form submits `category_whitelist`
     * comma-separated; it must land in api_config as a trimmed
     * string[], and a blank value as [] (not [""]).
     */
    public function test_update_normalizes_the_digiflazz_category_whitelist_to_an_array(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier([
            'slug' => 'digiflazz',
            'api_config' => ['base_url' => 'https://api.digiflazz.com', 'username' => 'u', 'api_key' => 'k'],
        ]);

        $this->putJson("/api/middleware/suppliers/{$supplier->id}", [
            'api_config' => ['category_whitelist' => 'Games, Voucher ,, '],
        ])->assertOk();

        $this->assertSame(['Games', 'Voucher'], $supplier->fresh()->api_config['category_whitelist']);

        $this->putJson("/api/middleware/suppliers/{$supplier->id}", [
            'api_config' => ['category_whitelist' => '  '],
        ])->assertOk();

        $this->assertSame([], $supplier->fresh()->api_config['category_whitelist']);
    }

    /**
     * ADR-067 decision 2: category_whitelist is optional — a digiflazz
     * row without it is still "fully configured" and its adapter still
     * binds.
     */
    public function test_digiflazz_without_a_category_whitelist_is_still_fully_configured(): void
    {
        $this->actingAsAdmin();
        $this->supplier([
            'slug' => 'digiflazz',
            'api_config' => [
                'base_url' => 'https://api.digiflazz.com', 'username' => 'u', 'api_key' => 'k',
                'testing' => false, 'customer_no_separator' => '',
            ],
        ]);

        $row = collect($this->getJson('/api/middleware/suppliers')->assertOk()->json())
            ->firstWhere('slug', 'digiflazz');

        $this->assertTrue($row['is_fully_configured']);
    }

    public function test_destroy_is_blocked_when_packages_reference_the_supplier(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire']);
        Package::query()->create([
            'game_id' => $game->id,
            'supplier_id' => $supplier->id,
            'name' => '100 Diamonds',
            'cost_price' => 1000,
            'standard_selling_price' => 1200,
            'markup_percent' => 20,
            'supplier_package_ref' => 'ref-'.uniqid(),
        ]);

        $this->deleteJson("/api/middleware/suppliers/{$supplier->id}")->assertStatus(422);
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_destroy_succeeds_for_a_zero_reference_supplier(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier(['slug' => 'digiflazz', 'name' => 'Digiflazz']);

        $this->deleteJson("/api/middleware/suppliers/{$supplier->id}")->assertNoContent();
        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_update_probes_the_connection_after_an_api_config_change(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier(['api_config' => ['base_url' => 'https://api.gamevion.com', 'bearer_token' => 'old', 'api_key' => 'old-k', 'sandbox' => false]]);
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(true, ['balance' => 4242]));

        $response = $this->putJson("/api/middleware/suppliers/{$supplier->id}", [
            'api_config' => ['bearer_token' => 'rotated'],
        ])->assertOk();

        $response->assertJsonPath('connection_probe.connection_ok', true);
        $this->assertEquals(4242, $response->json('connection_probe.balance'));
        // The probe also refreshes the stored balance / last-tested marker.
        $this->assertEquals(4242, $supplier->fresh()->balance);
    }

    public function test_update_surfaces_a_failed_connection_probe_without_failing_the_save(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier(['api_config' => ['base_url' => 'https://api.gamevion.com', 'bearer_token' => 'old', 'api_key' => 'old-k', 'sandbox' => false]]);
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(false));

        $response = $this->putJson("/api/middleware/suppliers/{$supplier->id}", [
            'api_config' => ['bearer_token' => 'rotated'],
        ])->assertOk();

        $response->assertJsonPath('connection_probe.connection_ok', false);
        $this->assertNotNull($response->json('connection_probe.error'));
        // The save still went through.
        $this->assertSame('rotated', $supplier->fresh()->api_config['bearer_token']);
    }

    public function test_update_probe_treats_a_breaker_open_result_as_not_a_credential_failure(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier(['api_config' => ['base_url' => 'https://api.gamevion.com', 'bearer_token' => 'old', 'api_key' => 'old-k', 'sandbox' => false]]);
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(false, [], 'CIRCUIT_OPEN'));

        $response = $this->putJson("/api/middleware/suppliers/{$supplier->id}", [
            'api_config' => ['bearer_token' => 'rotated'],
        ])->assertOk();

        $response->assertJsonPath('connection_probe.connection_ok', false);
        $response->assertJsonPath('connection_probe.breaker_open', true);
        // The breaker case must NOT stamp last_test_result as a failure —
        // it says nothing about the credential just saved.
        $this->assertNull($supplier->fresh()->last_test_result);
    }

    public function test_update_without_an_api_config_change_does_not_probe(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier();

        $response = $this->putJson("/api/middleware/suppliers/{$supplier->id}", [
            'name' => 'Gamevion Renamed',
        ])->assertOk();

        $this->assertNull($response->json('connection_probe'));
    }

    public function test_refresh_balance_calls_the_live_adapter_and_stores_the_result(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier();
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(true, ['balance' => 9999]));

        $response = $this->postJson("/api/middleware/suppliers/{$supplier->id}/refresh-balance")->assertOk();

        $response->assertJsonPath('last_test_result', 'success');
        $this->assertNotNull($response->json('last_tested_at'));
        $this->assertEquals(9999, $response->json('balance'));
    }

    public function test_refresh_balance_records_a_failure(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier();
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter(false));

        $response = $this->postJson("/api/middleware/suppliers/{$supplier->id}/refresh-balance")->assertOk();

        $this->assertStringContainsString('failed', $response->json('last_test_result'));
    }

    /**
     * Regression test for the real 2026-08-28 crash: a supplier row
     * with *some* api_config keys saved (e.g. base_url from a partial
     * Edit save) but not all of them used to bypass the old
     * empty()-only guard in AppServiceProvider::supplierApiConfig()
     * and crash with an uncaught "Undefined array key" deep inside the
     * adapter binding closure, instead of the clean 422 this asserts.
     * Deliberately does not rebind 'supplier-adapter.gamevion' — this
     * needs to exercise the real container binding, not a test fake.
     */
    public function test_refresh_balance_with_partially_configured_credentials_returns_a_clean_error(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier(['api_config' => ['base_url' => 'https://api.gamevion.com']]);

        $response = $this->postJson("/api/middleware/suppliers/{$supplier->id}/refresh-balance");

        $response->assertStatus(422);
        $this->assertStringContainsString('bearer_token', $response->json('errors.supplier.0'));
    }

    public function test_deactivate_all_turns_off_every_package_and_writes_audit_rows(): void
    {
        $admin = $this->actingAsAdmin();
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire']);
        $package = Package::query()->create([
            'game_id' => $game->id,
            'supplier_id' => $supplier->id,
            'name' => '100 Diamonds',
            'cost_price' => 1000,
            'standard_selling_price' => 1200,
            'markup_percent' => 20,
            'is_active' => true,
            'supplier_package_ref' => 'ref-'.uniqid(),
        ]);

        $this->patchJson("/api/middleware/suppliers/{$supplier->id}/packages/status", [
            'is_active' => false,
            'reason' => 'Gamevion delay reported by customers',
        ])->assertOk()->assertJson(['updated' => 1]);

        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame('supplier_issue', $package->deactivated_reason);
        $this->assertNotNull($package->deactivated_at);

        $log = DeactivationLog::query()->where('package_id', $package->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame('Gamevion delay reported by customers', $log->reason);
        $this->assertNull($log->price_sync_run_id);
    }

    public function test_deactivate_by_game_only_affects_the_scoped_game(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier();
        $affectedGame = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire']);
        $otherGame = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);

        $affected = Package::query()->create([
            'game_id' => $affectedGame->id, 'supplier_id' => $supplier->id, 'name' => 'A',
            'cost_price' => 1000, 'standard_selling_price' => 1200, 'markup_percent' => 20, 'is_active' => true, 'supplier_package_ref' => 'ref-'.uniqid(),
        ]);
        $unaffected = Package::query()->create([
            'game_id' => $otherGame->id, 'supplier_id' => $supplier->id, 'name' => 'B',
            'cost_price' => 1000, 'standard_selling_price' => 1200, 'markup_percent' => 20, 'is_active' => true, 'supplier_package_ref' => 'ref-'.uniqid(),
        ]);

        $this->patchJson("/api/middleware/suppliers/{$supplier->id}/packages/status", [
            'is_active' => false,
            'game_id' => $affectedGame->id,
        ])->assertOk()->assertJson(['updated' => 1]);

        $this->assertFalse($affected->fresh()->is_active);
        $this->assertTrue($unaffected->fresh()->is_active);
    }

    public function test_reactivate_only_restores_packages_this_mechanism_turned_off(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire']);

        $bySupplierIssue = Package::query()->create([
            'game_id' => $game->id, 'supplier_id' => $supplier->id, 'name' => 'A',
            'cost_price' => 1000, 'standard_selling_price' => 1200, 'markup_percent' => 20, 'supplier_package_ref' => 'ref-'.uniqid(),
            'is_active' => false, 'deactivated_reason' => 'supplier_issue', 'deactivated_at' => now(),
        ]);
        $byPriceAnomaly = Package::query()->create([
            'game_id' => $game->id, 'supplier_id' => $supplier->id, 'name' => 'B',
            'cost_price' => 1000, 'standard_selling_price' => 1200, 'markup_percent' => 20, 'supplier_package_ref' => 'ref-'.uniqid(),
            'is_active' => false, 'deactivated_reason' => 'price_anomaly', 'deactivated_at' => now(),
        ]);

        $this->patchJson("/api/middleware/suppliers/{$supplier->id}/packages/status", [
            'is_active' => true,
        ])->assertOk()->assertJson(['updated' => 1]);

        $this->assertTrue($bySupplierIssue->fresh()->is_active);
        $this->assertNull($bySupplierIssue->fresh()->deactivated_reason);
        $this->assertFalse($byPriceAnomaly->fresh()->is_active, 'price_anomaly deactivations must not be swept up by a supplier-issue reactivate');
    }
}
