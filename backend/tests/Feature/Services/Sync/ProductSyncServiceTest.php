<?php

namespace Tests\Feature\Services\Sync;

use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\SupplierTransfer;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierCatalogItem;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use App\Services\Sync\ProductSyncFailedException;
use App\Services\Sync\ProductSyncService;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProductSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ProductSyncService
    {
        return app(ProductSyncService::class);
    }

    /** ADR-134: a foreign supplier is priced at what its deposit cost — here 0.0239 sen a rupiah (market 0.0228). */
    private function fund(Supplier $supplier, int $myrSen = 23900, string $units = '1000000'): void
    {
        SupplierTransfer::query()->create([
            'transferred_on' => '2026-10-10', 'supplier_id' => $supplier->id, 'source_channel' => 'bank',
            'amount_myr_sent' => $myrSen, 'fee_myr' => 0, 'currency' => $supplier->currency, 'amount_foreign_received' => $units,
        ]);
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

        $result = $this->service()->sync($supplier, $adapter);

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
     * ADR-069 decision 10 — the adapter's `rawPrice` / `rawCurrency`
     * are persisted verbatim (display-only), with no per-supplier
     * branching in the sync layer. `price_sen` stays the converted
     * value everything downstream reads.
     */
    public function test_sync_persists_the_adapter_supplied_raw_price_and_currency(): void
    {
        $supplier = $this->supplier();
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem(
                productRef: 'xld10',
                name: 'MLBB 10 Diamonds',
                category: 'Games',
                price: 4.56,
                status: 'active',
                groupLabel: 'MOBILE LEGENDS — Malaysia',
                type: 'Malaysia',
                rawPrice: 20000.0,
                rawCurrency: 'IDR',
            ),
            new SupplierCatalogItem('NOPRICE', 'Unpriced item', 'Games', null, 'active'),
        ]);

        $this->service()->sync($supplier, $adapter);

        $row = SupplierProduct::query()->where('external_ref', 'xld10')->firstOrFail();
        $this->assertSame('20000.00', $row->raw_price);
        $this->assertSame('IDR', $row->raw_currency);

        $unpriced = SupplierProduct::query()->where('external_ref', 'NOPRICE')->firstOrFail();
        $this->assertNull($unpriced->raw_price);
        $this->assertNull($unpriced->raw_currency);
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

        $this->service()->sync($supplier, $adapter);

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

        $this->service()->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));

        $result = $this->service()->sync($supplier, $this->fakeAdapter(true, [
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

        $this->service()->sync($supplier, $this->fakeAdapter(true, [
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
        $this->fund($supplier);
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('xld10', 'MLBB 10 Diamonds', 'Mobile Legends', 3200.0, 'active'),
            new SupplierCatalogItem('pln20', 'PLN Token 20k', 'PLN Prepaid', 21000.0, 'active'),
        ]);

        $result = $this->service()->sync($supplier, $adapter);

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
        $this->fund($supplier);
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('xld10', 'MLBB 10 Diamonds', 'Mobile Legends', 3200.0, 'active'),
            new SupplierCatalogItem('pln20', 'PLN Token 20k', 'PLN Prepaid', 21000.0, 'active'),
        ]);

        $result = $this->service()->sync($supplier, $adapter);

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

        $this->service()->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
            new SupplierCatalogItem('PLN20', 'PLN Token', 'PLN', 21.0, 'active'),
        ]));
        $this->assertSame(2, SupplierProduct::query()->count());

        // Second run: PLN20 is gone from the catalog.
        $result = $this->service()->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));

        $this->assertSame(1, $result->pruned);
        $this->assertSame(['FFP5'], SupplierProduct::query()->pluck('external_ref')->all());
    }

    public function test_sync_keeps_a_promoted_row_the_supplier_no_longer_returns(): void
    {
        $supplier = $this->supplier();
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire']);

        $this->service()->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
            new SupplierCatalogItem('FFP99', 'Free Fire 99', 'Free Fire', 90.0, 'active'),
        ]));
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'FF 99', 'cost_price' => 9000, 'standard_selling_price' => 10000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'FFP99',
        ]);

        $result = $this->service()->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));

        $this->assertSame(0, $result->pruned);
        // FFP99's row stays so PackagePriceSyncService can deactivate its Package.
        $this->assertTrue(SupplierProduct::query()->where('external_ref', 'FFP99')->exists());
    }

    public function test_sync_prunes_nothing_when_the_filtered_catalog_is_empty(): void
    {
        $supplier = $this->supplier(['api_config' => ['category_whitelist' => ['Nonexistent Category']]]);

        $this->service()->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));
        // First run wrote nothing (whitelist matched nothing) — seed a row directly.
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'OLD1', 'name' => 'Old', 'category_raw' => 'Games',
            'group_label' => 'Games', 'price_sen' => 100, 'status_raw' => 'active', 'last_synced_at' => now()->subDay(),
        ]);

        $result = $this->service()->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));

        $this->assertSame(0, $result->pruned);
        $this->assertTrue(SupplierProduct::query()->where('external_ref', 'OLD1')->exists());
    }

    /**
     * ADR-033 decision 3, ADR-134 decision 5: the single conversion
     * boundary — a non-MYR supplier's raw price becomes MYR sen here, at
     * what its deposit cost, rounded up (ADR-033's cushion). The market
     * mid-rate is recorded beside it, with the spread.
     */
    public function test_sync_prices_a_non_myr_supplier_at_its_landed_cost_rate(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000228]], 200)]);
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => []]);
        $this->fund($supplier);
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('xld10', 'MLBB 10 Diamonds', 'Mobile Legends', 3200.0, 'active'),
        ]);

        $result = $this->service()->sync($supplier, $adapter);

        // 3200 IDR × 0.0239 sen = 76.48 sen -> ceil -> 77 sen (the market rate would have said 73).
        $this->assertSame(77, SupplierProduct::query()->firstOrFail()->price_sen);
        $this->assertEqualsWithDelta(0.000239, $result->fxRateUsed['rate'], 1e-12);
        $this->assertSame('deposit pool', $result->fxRateUsed['source']);
        $this->assertSame(0.000228, $result->fxRateUsed['market_rate']);
        $this->assertSame(4.82, $result->fxRateUsed['spread_pct']);
    }

    /** ADR-111 2026-10-14 addendum: one rate per run — a market rate that changes mid-run moves no price. */
    public function test_sync_prices_every_item_at_the_one_rate_the_run_records(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000228]], 200)]);
        Event::listen(CacheHit::class, function (CacheHit $event) {
            if ($event->key === 'currency_rate:IDR:MYR') {
                Cache::put('currency_rate:IDR:MYR', 0.001, 60);
            }
        });
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => []]);
        $this->fund($supplier);
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('a', 'A', 'Mobile Legends', 3200.0, 'active'),
            new SupplierCatalogItem('b', 'B', 'Mobile Legends', 3200.0, 'active'),
            new SupplierCatalogItem('c', 'C', 'Mobile Legends', 3200.0, 'active'),
        ]);

        $result = $this->service()->sync($supplier, $adapter);

        $this->assertEqualsWithDelta(0.000239, $result->fxRateUsed['rate'], 1e-12);
        $this->assertSame([77, 77, 77], SupplierProduct::query()->orderBy('external_ref')->pluck('price_sen')->all());
    }

    public function test_sync_leaves_fx_rate_used_null_for_a_myr_supplier(): void
    {
        $supplier = $this->supplier();
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]);

        $result = $this->service()->sync($supplier, $adapter);

        $this->assertNull($result->fxRateUsed);
    }

    /** ADR-134 decision 7: the market rate is display-only, so an FX outage no longer stops a funded supplier's sync. */
    public function test_an_fx_outage_still_prices_a_funded_supplier(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['message' => 'Server error'], 500)]);
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => []]);
        $this->fund($supplier);

        $result = $this->service()->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('xld10', 'MLBB 10 Diamonds', 'Mobile Legends', 3200.0, 'active'),
        ]));

        $this->assertSame(77, SupplierProduct::query()->firstOrFail()->price_sen);
        $this->assertNull($result->fxRateUsed['market_rate']);
        $this->assertNull($result->fxRateUsed['spread_pct']);
    }

    /** ADR-134 decision 4: a foreign supplier with no deposit has no cost to price by, so the run writes nothing. */
    public function test_sync_refuses_a_foreign_supplier_with_no_deposit(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000228]], 200)]);
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => []]);

        try {
            $this->service()->sync($supplier, $this->fakeAdapter(true, [
                new SupplierCatalogItem('xld10', 'MLBB 10 Diamonds', 'Mobile Legends', 3200.0, 'active'),
            ]));
            $this->fail('Expected ProductSyncFailedException was not thrown.');
        } catch (ProductSyncFailedException $e) {
            $this->assertStringContainsString('no deposit is recorded', $e->getMessage());
        }

        $this->assertSame(0, SupplierProduct::query()->count());
    }

    public function test_sync_throws_and_writes_nothing_when_the_adapter_call_fails(): void
    {
        $supplier = $this->supplier();
        $adapter = $this->fakeAdapter(false, [], 'timeout', 'Gamevion did not respond');

        try {
            $this->service()->sync($supplier, $adapter);
            $this->fail('Expected ProductSyncFailedException was not thrown.');
        } catch (ProductSyncFailedException) {
            // expected
        }

        $this->assertSame(0, SupplierProduct::query()->count());
    }

    /**
     * ADR-100 — Digiflazz's own `start_cut_off`/`end_cut_off`, carried
     * from the adapter's `SupplierCatalogItem` straight onto the row,
     * unvalidated (PendingReactivationAutoApprover decides what an
     * ambiguous value means, not this pure-mirror stage).
     */
    public function test_sync_persists_the_cutoff_window_fields(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000228]], 200)]);
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => []]);
        $this->fund($supplier);
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem(
                'xld10', 'MLBB 10 Diamonds', 'Mobile Legends', 3200.0, 'active',
                cutOffStart: '23:00', cutOffEnd: '01:00',
            ),
        ]);

        $this->service()->sync($supplier, $adapter);

        $row = SupplierProduct::query()->where('external_ref', 'xld10')->firstOrFail();
        $this->assertSame('23:00', $row->cutoff_start);
        $this->assertSame('01:00', $row->cutoff_end);
    }

    /**
     * ADR-100 — `consecutive_active_syncs` climbs by one per run while
     * a row stays 'active', starting from 0 on first creation.
     */
    public function test_sync_increments_the_consecutive_active_streak_across_runs(): void
    {
        $supplier = $this->supplier();
        $adapter = $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]);
        $service = $this->service();

        $service->sync($supplier, $adapter);
        $this->assertSame(1, SupplierProduct::query()->where('external_ref', 'FFP5')->value('consecutive_active_syncs'));

        $service->sync($supplier, $adapter);
        $this->assertSame(2, SupplierProduct::query()->where('external_ref', 'FFP5')->value('consecutive_active_syncs'));

        $service->sync($supplier, $adapter);
        $this->assertSame(3, SupplierProduct::query()->where('external_ref', 'FFP5')->value('consecutive_active_syncs'));
    }

    /**
     * ADR-100 — a single 'inactive' run resets the streak to 0
     * outright, not a decrement — the N-consecutive-sync gate needs an
     * unbroken run, not a rolling average.
     */
    public function test_sync_resets_the_consecutive_active_streak_the_moment_status_flips_inactive(): void
    {
        $supplier = $this->supplier();
        $service = $this->service();

        $service->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));
        $service->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));
        $this->assertSame(2, SupplierProduct::query()->where('external_ref', 'FFP5')->value('consecutive_active_syncs'));

        $service->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'inactive'),
        ]));
        $this->assertSame(0, SupplierProduct::query()->where('external_ref', 'FFP5')->value('consecutive_active_syncs'));

        $service->sync($supplier, $this->fakeAdapter(true, [
            new SupplierCatalogItem('FFP5', 'Free Fire 5 Diamonds', 'Free Fire', 10.0, 'active'),
        ]));
        $this->assertSame(1, SupplierProduct::query()->where('external_ref', 'FFP5')->value('consecutive_active_syncs'));
    }
}
