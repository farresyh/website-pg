<?php

namespace Tests;

use App\Models\Affiliate;
use App\Support\OutboundUrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Wave 3 S-3: `*.test` hostnames never resolve, so a test that saves
     * or delivers to a reseller webhook URL resolves every host to one
     * public address unless `$dns` maps it elsewhere.
     *
     * @param  array<string, list<string>>  $dns
     */
    protected function fakeOutboundDns(array $dns = []): void
    {
        $this->app->instance(
            OutboundUrlGuard::class,
            new OutboundUrlGuard(fn (string $host) => $dns[$host] ?? ['93.184.215.14']),
        );
    }

    /**
     * ADR-061: the single `is_primary` Affiliate — the fallback tenant
     * every non-`Host` code path resolves via `Affiliate::primary()`.
     * Tests never seed, so each test that exercises the platform's own
     * storefront creates it here. `firstOrCreate` on `is_primary` keeps a
     * second call in the same test idempotent without tripping the
     * nullable-unique index (the real primary stores `1`, everyone else
     * `NULL`).
     *
     * `membership_enabled` defaults true so the effective Membership gate
     * (`Affiliate::membershipEnabledEffective()`) reduces to the global
     * `PlatformSettings.membership_enabled` switch — the single control
     * every pre-ADR-061 Membership test was written against. Tests that
     * exercise the per-brand half of the dual switch pass it explicitly.
     */
    protected function primaryAffiliate(array $attributes = []): Affiliate
    {
        return Affiliate::query()->firstOrCreate(
            ['is_primary' => true],
            array_merge([
                'business_name' => 'Platform Owner',
                'markup_pct' => 0,
                'status' => 'active',
                'is_owned' => true,
                'membership_enabled' => true,
            ], $attributes),
        );
    }
}
