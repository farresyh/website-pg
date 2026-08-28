<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-054 (DEV-1/2, MUI-11) — Developer API Tester. Mirrors
 * SupplierControllerTest's fake-adapter pattern: an anonymous
 * SupplierAdapter rebound onto the container, not the real HTTP
 * client — call_type-prefix logging (decision 7) is covered instead
 * at the adapter level, see GamevionAdapterTest.
 */
class DeveloperToolControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function fullyConfiguredSupplier(bool $sandbox = true): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'currency' => 'MYR',
            'api_config' => [
                'base_url' => 'https://api.gamevion.com',
                'bearer_token' => 'secret-token',
                'api_key' => 'secret-key',
                'sandbox' => $sandbox,
            ],
        ]);
    }

    private function fakeAdapter(bool $success = true): SupplierAdapter
    {
        return new class($success) implements SupplierAdapter
        {
            public function __construct(private bool $success)
            {
            }

            public function checkBalance(): SupplierResponse
            {
                return SupplierResponse::success(['balance' => 5000]);
            }

            public function listProducts(): SupplierResponse
            {
                return SupplierResponse::success([]);
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                return $this->success
                    ? SupplierResponse::success(['supplier_ref' => 'GV-1', 'reference_number' => $request->referenceNumber])
                    : SupplierResponse::failure('INVALID_PRODUCT', 'unknown product');
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                return SupplierResponse::success(['status' => 'delivered']);
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('Gamevion has no dedicated validation endpoint');
            }
        };
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->postJson('/api/middleware/developer-tools/test', [])->assertForbidden();
    }

    public function test_dry_run_never_resolves_an_adapter_and_previews_the_request(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->fullyConfiguredSupplier();

        // Deliberately no supplier-adapter.gamevion rebind — a dry-run
        // must never touch the adapter layer at all (decision 3).
        $response = $this->postJson('/api/middleware/developer-tools/test', [
            'supplier_id' => $supplier->id,
            'method' => 'createOrder',
            'dry_run' => true,
            'payload' => ['product_ref' => 'diamond-100', 'player_id' => '12345678'],
        ])->assertOk();

        $response->assertJsonPath('dry_run', true);
        $this->assertSame('diamond-100', $response->json('request.productRef'));
        $this->assertSame('12345678', $response->json('request.playerId'));
        $this->assertStringStartsWith('DEVTEST-', $response->json('request.referenceNumber'));
    }

    public function test_rejects_a_call_against_a_not_fully_configured_supplier(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'currency' => 'MYR',
            'api_config' => ['base_url' => 'https://api.gamevion.com'],
        ]);

        $response = $this->postJson('/api/middleware/developer-tools/test', [
            'supplier_id' => $supplier->id,
            'method' => 'checkBalance',
            'dry_run' => true,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('bearer_token', $response->json('errors.supplier.0'));
    }

    /**
     * Decision 4's load-bearing safety rule: createOrder must never
     * live-fire against a supplier that isn't confirmed sandbox/testing.
     */
    public function test_live_create_order_is_blocked_when_supplier_is_in_production_mode(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->fullyConfiguredSupplier(sandbox: false);
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter());

        $response = $this->postJson('/api/middleware/developer-tools/test', [
            'supplier_id' => $supplier->id,
            'method' => 'createOrder',
            'dry_run' => false,
            'payload' => ['product_ref' => 'diamond-100', 'player_id' => '12345678'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('production mode', $response->json('errors.method.0'));
    }

    public function test_live_create_order_succeeds_against_a_sandboxed_supplier(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->fullyConfiguredSupplier(sandbox: true);
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter());

        $response = $this->postJson('/api/middleware/developer-tools/test', [
            'supplier_id' => $supplier->id,
            'method' => 'createOrder',
            'dry_run' => false,
            'payload' => ['product_ref' => 'diamond-100', 'player_id' => '12345678'],
        ])->assertOk();

        $response->assertJsonPath('dry_run', false);
        $response->assertJsonPath('success', true);
        $this->assertStringStartsWith('DEVTEST-', $response->json('data.reference_number'));
    }

    /** checkBalance/listProducts/checkStatus/validatePlayer may live-fire regardless of sandbox mode. */
    public function test_read_only_methods_may_live_fire_against_a_production_supplier(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->fullyConfiguredSupplier(sandbox: false);
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter());

        $response = $this->postJson('/api/middleware/developer-tools/test', [
            'supplier_id' => $supplier->id,
            'method' => 'checkBalance',
            'dry_run' => false,
        ])->assertOk();

        $response->assertJsonPath('success', true);
        $this->assertSame(5000, $response->json('data.balance'));
    }

    public function test_validate_player_surfaces_a_not_supported_error_cleanly(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->fullyConfiguredSupplier();
        $this->app->bind('supplier-adapter.gamevion', fn () => $this->fakeAdapter());

        $response = $this->postJson('/api/middleware/developer-tools/test', [
            'supplier_id' => $supplier->id,
            'method' => 'validatePlayer',
            'dry_run' => false,
            'payload' => ['player_id' => '12345678'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('no dedicated validation endpoint', $response->json('errors.method.0'));
    }
}
