<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a SupplierAdapter implementation by supplier **driver** — the
 * supplier-side mirror of PaymentGatewayFactory (ADR-031). ADR-125
 * decision 1: the binding key is `supplier-adapter.<driver>`; an API
 * supplier's driver equals its slug, and every manual supplier shares
 * `manual`. An unbound driver throws rather than silently falling back,
 * since that would misroute a real order to the wrong supplier.
 *
 * Resolves through the container rather than constructing adapters
 * directly, so tests can rebind a single fake per driver and have this
 * factory pick it up uniformly (see PaymentGatewayFactoryTest's own
 * precedent).
 */
final class SupplierAdapterFactory
{
    public function __construct(private readonly Container $container) {}

    public function for(Supplier $supplier): SupplierAdapter
    {
        return $this->make($supplier->driver);
    }

    public function make(string $driver): SupplierAdapter
    {
        $key = "supplier-adapter.{$driver}";

        if (! $this->container->bound($key)) {
            throw new UnsupportedSupplierException(
                "No SupplierAdapter implementation for driver: {$driver}",
            );
        }

        return $this->container->make($key);
    }

    /**
     * ADR-102 addendum (2026-09-29): suppliers whose re-submit of the SAME
     * reference replays the stored outcome instead of creating a second
     * transaction — Digiflazz's own checkStatus() *is* that re-submit.
     * For these, an ambiguous outcome is safely auto-polled (Pending)
     * rather than handed to an admin (NeedsReview). Gamevion answers a
     * repeat with `duplicate_reference` and no status, so it's absent.
     * A protocol fact per supplier, not a business setting.
     */
    private const RESUBMIT_REPLAYS_OUTCOME = ['digiflazz'];

    public static function resubmitReplaysOutcome(?Supplier $supplier): bool
    {
        return $supplier !== null && in_array($supplier->driver, self::RESUBMIT_REPLAYS_OUTCOME, true);
    }

    /**
     * ADR-046 decision 4: the Supplier Management screen's driver
     * dropdown reads this rather than a hardcoded list, so it stays
     * accurate the moment a new supplier gets its own container
     * binding — no separate list to keep in sync (and keeps this
     * factory, not the admin-CRUD layer, as the one place that knows
     * which drivers actually have an implementation).
     *
     * @return list<string>
     */
    public function registeredDrivers(): array
    {
        return collect(array_keys($this->container->getBindings()))
            ->filter(fn (string $key) => str_starts_with($key, 'supplier-adapter.'))
            ->map(fn (string $key) => substr($key, strlen('supplier-adapter.')))
            ->values()
            ->all();
    }
}
