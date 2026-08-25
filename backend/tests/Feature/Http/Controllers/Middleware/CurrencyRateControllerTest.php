<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\CurrencyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-033 addendum decision 1/4: the FX Rate History section on Price
 * Sync Center — every rate ever fetched, paginated, newest first,
 * generic across any currency pair.
 */
class CurrencyRateControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/middleware/price-sync/fx-rates')->assertForbidden();
    }

    public function test_index_returns_every_rate_newest_first(): void
    {
        $this->actingAsAdmin();
        $older = CurrencyRate::query()->create(['from' => 'IDR', 'to' => 'MYR', 'rate' => 0.000230, 'source' => 'open.er-api.com', 'fetched_at' => now()->subDay()]);
        $newer = CurrencyRate::query()->create(['from' => 'IDR', 'to' => 'MYR', 'rate' => 0.000228, 'source' => 'open.er-api.com', 'fetched_at' => now()]);

        $response = $this->getJson('/api/middleware/price-sync/fx-rates');

        $response->assertOk();
        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
    }

    /** ADR-033 addendum decision 2: generic, works for any pair — proven with two different ones. */
    public function test_index_includes_every_currency_pair(): void
    {
        $this->actingAsAdmin();
        CurrencyRate::query()->create(['from' => 'IDR', 'to' => 'MYR', 'rate' => 0.000228, 'source' => 'open.er-api.com', 'fetched_at' => now()]);
        CurrencyRate::query()->create(['from' => 'PHP', 'to' => 'MYR', 'rate' => 0.078, 'source' => 'open.er-api.com', 'fetched_at' => now()]);

        $response = $this->getJson('/api/middleware/price-sync/fx-rates');

        $this->assertCount(2, $response->json('data'));
    }

    /** ADR-033 addendum decision 4: paginated, not a time-capped window. */
    public function test_index_is_paginated(): void
    {
        $this->actingAsAdmin();
        for ($i = 0; $i < 25; $i++) {
            CurrencyRate::query()->create(['from' => 'IDR', 'to' => 'MYR', 'rate' => 0.000228, 'source' => 'open.er-api.com', 'fetched_at' => now()->subMinutes($i)]);
        }

        $response = $this->getJson('/api/middleware/price-sync/fx-rates?per_page=10');

        $response->assertOk();
        $this->assertCount(10, $response->json('data'));
        $this->assertSame(25, $response->json('total'));
    }
}
