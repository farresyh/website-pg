<?php

namespace App\Services\Supplier;

use Illuminate\Contracts\Container\Container;

/**
 * Resolves a SupplierAdapter implementation by supplier slug — the
 * supplier-side mirror of PaymentGatewayFactory (ADR-031, following
 * the already-proven pattern on the payment side). `'gamevion'` is
 * bound today, as `supplier-adapter.gamevion` in AppServiceProvider;
 * any other slug throws rather than silently falling back to
 * Gamevion, since that would misroute a real order to the wrong
 * supplier. A second supplier is added here only once it's actually
 * researched and built against its real API (ADR-030) — this factory
 * does not speculate on that shape.
 *
 * Resolves through the container (by a `supplier-adapter.<slug>`
 * binding key) rather than constructing adapters directly, so tests
 * can rebind a single fake per supplier and have this factory pick it
 * up uniformly (see PaymentGatewayFactoryTest's own precedent).
 */
final class SupplierAdapterFactory
{
    public function __construct(private readonly Container $container)
    {
    }

    public function make(string $slug): SupplierAdapter
    {
        $key = "supplier-adapter.{$slug}";

        if (! $this->container->bound($key)) {
            throw new UnsupportedSupplierException(
                "No SupplierAdapter implementation for supplier: {$slug}",
            );
        }

        return $this->container->make($key);
    }

    /**
     * ADR-046 decision 4: the Supplier Management screen's `slug`
     * dropdown reads this rather than a hardcoded list, so it stays
     * accurate the moment a new supplier gets its own container
     * binding — no separate list to keep in sync (and keeps this
     * factory, not the admin-CRUD layer, as the one place that knows
     * which slugs actually have an implementation).
     *
     * @return list<string>
     */
    public function registeredSlugs(): array
    {
        return collect(array_keys($this->container->getBindings()))
            ->filter(fn (string $key) => str_starts_with($key, 'supplier-adapter.'))
            ->map(fn (string $key) => substr($key, strlen('supplier-adapter.')))
            ->values()
            ->all();
    }
}
