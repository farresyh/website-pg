<?php

namespace Tests\Feature\Services\Sync;

use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Currency\CurrencyRateService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierCatalogItem;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use App\Services\Sync\ProductSyncFailedException;
use App\Services\Sync\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProductSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => [],
            'currency' => 'MYR',
        ], $overrides));
    }

    /**
     * @param  SupplierCatalogItem[]  $items
     */
    private function fakeAdapter(bool $success, array $items = [], ?string $errorCode = null, ?string $errorMessage = null): SupplierAdapter
    {
        return new class($success, $items, $errorCode, $errorMessage) implements SupplierAdapter
        {
            public function __construct(
                private readonly bool $success,
                private readonly array $items,
                private readonly ?string $errorCode,
                private readonly ?string $errorMessage,
            ) {}

            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function listProducts(): SupplierResponse
            {
                return $this->success
                    ? SupplierResponse::success($this->items)
                    : SupplierResponse::failure($this->errorCode, $this->errorMessage);
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        };
    }

    public function test_sync_creates_a_supplier_product_row_per_listed_item(): void
    {
        $supplier = $this->supplier();
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
            new SupplierCatalogItem('MLBB14', '14 Diamond (13+1 Bonus)', 'MOBA', 11.64, 'active'),
        ]);

        $result = (new ProductSyncService(new CurrencyRateService))->sync($supplier, $adapter);

        $this->assertSame(2, $result->total);
        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame(2, SupplierProduct::query()->count());

        $row = SupplierProduct::query()->where('external_ref', 'MLBB14')->firstOrFail();
        $this->assertSame('14 Diamond (13+1 Bonus)', $row->name);
        $this->assertSame('MOBA', $row->category_raw);
        $this->assertSame(1164, $row->price_sen); // 11.64 MYR -> sen
        $this->assertSame('active', $row->status_raw);
        $this->assertNotNull($row->last_synced_at);
        // ADR-067 decision 4: no explicit groupLabel from the adapter
        // falls back to category.
        $this->assertSame('MOBA', $row->group_label);
        $this->assertNull($row->type);
    }

    /**
     * ADR-067 decision 4/5: when an adapter sets `groupLabel` / `type`
     * explicitly (Digiflazz passes `brand` / `type`), those are stored
     * verbatim — `group_label` is NOT the raw `category` in that case.
     */
    public function test_sync_stores_adapter_supplied_group_label_and_type(): void
    {
        $supplier = $this->supplier();
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem(
                productRef: 'ml86',
                name: 'Mobile Legends 86 Diamonds',
                category: 'Games',
                price: 20000.0,
                status: 'active',
                groupLabel: 'MOBILE LEGENDS',
                type: 'Umum',
            ),
        ]);

        (new ProductSyncService(new CurrencyRateService))->sync($supplier, $adapter);

        $row = SupplierProduct::query()->where('external_ref', 'ml86')->firstOrFail();
        $this->assertSame('Games', $row->category_raw);
        $this->assertSame('MOBILE LEGENDS', $row->group_label);
        $this->assertSame('Umum', $row->type);
    }

    /**
     * Re-running sync must not create duplicate rows for the same
     * supplier + external_ref — it upserts (SYNC-4 is safe to trigger
     * repeatedly, including on a schedule).
     */
    public function test_sync_upserts_an_existing_item_by_supplier_and_external_ref(): void
    {
        $supplier = $this->supplier();

        (new ProductSyncService(new CurrencyRateService))->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));

        $result = (new ProductSyncService(new CurrencyRateService))->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds (renamed)', 'Free Fire', 12.5, 'active'),
        ]));

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, SupplierProduct::query()->count());

        $row = SupplierProduct::query()->firstOrFail();
        $this->assertSame('Free Fire 5 Diamonds (renamed)', $row->name);
        $this->assertSame(1250, $row->price_sen);
    }

    public function test_sync_keeps_a_null_price_when_the_supplier_sends_none(): void
    {
        $supplier = $this->supplier();

        (new ProductSyncService(new CurrencyRateService))->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', null, 'inactive'),
        ]));

        $this->assertNull(SupplierProduct::query()->firstOrFail()->price_sen);
    }

    /**
     * ADR-030 decision 2: the "games only" business filter lives here,
     * reading a category whitelist from Supplier.api_config — never in
     * the adapter, which stays protocol-faithful and returns the full
     * prepaid catalog (Digiflazz's real catalog includes PLN/pulsa/
     * non-game items this platform never sells).
     */
    public function test_sync_filters_by_category_whitelist_when_the_supplier_has_one_configured(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000228]], 200)]);
        $supplier = Supplier::query()->create([
            'name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR',
            'api_config' => ['category_whitelist' => ['Mobile Legends']],
        ]);
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('xld10', 'MLBB 10 Diamonds', 'Mobile Legends', 3200.0, 'active'),
            new SupplierCatalogItem('pln20', 'PLN Token 20k', 'PLN Prepaid', 21000.0, 'active'),
        ]);

        $result = (new ProductSyncService(new CurrencyRateService))->sync($supplier, $adapter);

        $this->assertSame(1, $result->total);
        $this->assertSame(1, SupplierProduct::query()->count());
        $this->assertSame('xld10', SupplierProduct::query()->firstOrFail()->external_ref);
    }

    public function test_sync_mirrors_every_category_when_no_whitelist_is_configured(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000228]], 200)]);
        $supplier = Supplier::query()->create([
            'name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => [],
        ]);
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('xld10', 'MLBB 10 Diamonds', 'Mobile Legends', 3200.0, 'active'),
            new SupplierCatalogItem('pln20', 'PLN Token 20k', 'PLN Prepaid', 21000.0, 'active'),
        ]);

        $result = (new ProductSyncService(new CurrencyRateService))->sync($supplier, $adapter);

        $this->assertSame(2, $result->total);
        $this->assertSame(2, SupplierProduct::query()->count());
    }

    /**
     * ADR-067: a row a completed sync no longer returns (a category now
     * excluded by category_whitelist, or a product removed from the
     * Digiflazz buyer area) is deleted — but only if it was never
     * promoted, and never when the whole filtered list came back empty.
     */
    public function test_sync_prunes_an_unpromoted_row_the_supplier_no_longer_returns(): void
    {
        $supplier = $this->supplier();

        (new ProductSyncService(new CurrencyRateService))->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
            new SupplierCatalogItem('PLN20', 'PLN Token', 'PLN', 21.0, 'active'),
        ]));
        $this->assertSame(2, SupplierProduct::query()->count());

        // Second run: PLN20 is gone from the catalog.
        $result = (new ProductSyncService(new CurrencyRateService))->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));

        $this->assertSame(1, $result->pruned);
        $this->assertSame(['FFP5'], SupplierProduct::query()->pluck('external_ref')->all());
    }

    public function test_sync_keeps_a_promoted_row_the_supplier_no_longer_returns(): void
    {
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire']);

        (new ProductSyncService(new CurrencyRateService))->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
            new SupplierCatalogItem('FFP99', 'Free Fire 99', 'Free Fire', 90.0, 'active'),
        ]));
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'FF 99', 'cost_price' => 9000, 'standard_selling_price' => 10000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'FFP99',
        ]);

        $result = (new ProductSyncService(new CurrencyRateService))->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));

        $this->assertSame(0, $result->pruned);
        // FFP99's row stays so PackagePriceSyncService can deactivate its Package.
        $this->assertTrue(SupplierProduct::query()->where('external_ref', 'FFP99')->exists());
    }

    public function test_sync_prunes_nothing_when_the_filtered_catalog_is_empty(): void
    {
        $supplier = $this->supplier(['api_config' => ['category_whitelist' => ['Nonexistent Category']]]);

        (new ProductSyncService(new CurrencyRateService))->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));
        // First run wrote nothing (whitelist matched nothing) — seed a row directly.
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'OLD1', 'name' => 'Old', 'category_raw' => 'Games',
            'group_label' => 'Games', 'price_sen' => 100, 'status_raw' => 'active', 'last_synced_at' => now()->subDay(),
        ]);

        $result = (new ProductSyncService(new CurrencyRateService))->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));

        $this->assertSame(0, $result->pruned);
        $this->assertTrue(SupplierProduct::query()->where('external_ref', 'OLD1')->exists());
    }

    /**
     * ADR-033 decision 3: the single conversion boundary — a
     * non-MYR supplier's raw price is converted to MYR sen here, and
     * only here, using CurrencyRateService's fetched rate. `ceil`
     * (not `round`) protects margin by construction, per the founder's
     * own decision.
     */
    public function test_sync_converts_a_non_myr_supplier_price_to_myr_sen(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000228]], 200)]);
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => []]);
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('xld10', 'MLBB 10 Diamonds', 'Mobile Legends', 3200.0, 'active'),
        ]);

        $result = (new ProductSyncService(new CurrencyRateService))->sync($supplier, $adapter);

        // 3200 IDR * 0.000228 MYR/IDR = 0.7296 MYR = 72.96 sen -> ceil -> 73 sen.
        $this->assertSame(73, SupplierProduct::query()->firstOrFail()->price_sen);
        $this->assertSame(['from' => 'IDR', 'to' => 'MYR', 'rate' => 0.000228, 'source' => 'open.er-api.com'], $result->fxRateUsed);
    }

    public function test_sync_leaves_fx_rate_used_null_for_a_myr_supplier(): void
    {
        $supplier = $this->supplier();
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]);

        $result = (new ProductSyncService(new CurrencyRateService))->sync($supplier, $adapter);

        $this->assertNull($result->fxRateUsed);
    }

    /**
     * The fallback (CurrencyRateService's own concern) keeps a
     * one-off FX outage from silently corrupting a whole sync run —
     * it surfaces as the same ProductSyncFailedException a supplier
     * adapter failure already produces, only when NO rate exists to
     * fall back to at all (the vanishingly rare first-ever-fetch case).
     */
    public function test_sync_throws_when_the_fx_rate_is_unavailable_and_none_was_ever_stored(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['message' => 'Server error'], 500)]);
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => []]);
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('xld10', 'MLBB 10 Diamonds', 'Mobile Legends', 3200.0, 'active'),
        ]);

        $this->expectException(ProductSyncFailedException::class);

        (new ProductSyncService(new CurrencyRateService))->sync($supplier, $adapter);
    }

    public function test_sync_throws_and_writes_nothing_when_the_adapter_call_fails(): void
    {
        $supplier = $this->supplier();
        $adapter = $this->fakeAdapter(false, [], 'timeout', 'Gamevion did not respond');

        try {
            (new ProductSyncService(new CurrencyRateService))->sync($supplier, $adapter);
            $this->fail('Expected ProductSyncFailedException was not thrown.');
        } catch (ProductSyncFailedException) {
            // expected
        }

        $this->assertSame(0, SupplierProduct::query()->count());
    }
}
