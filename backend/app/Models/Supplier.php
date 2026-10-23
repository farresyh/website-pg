<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    /** ADR-125 decision 1: a supplier with no API, whose orders a person fulfils. */
    public const DRIVER_MANUAL = 'manual';

    public const FUNDING_PREPAID = 'prepaid';

    public const FUNDING_PAY_AFTER = 'pay_after';

    /** ADR-125 decision 3: a manual Pending order is stale this long after it was posted, unless `reply_sla_minutes` says otherwise. */
    public const DEFAULT_MANUAL_SLA_MINUTES = 30;

    /** ADR-126 decision 15: the one supplier a paid joki order waits on until it is assigned. */
    public const JOKI_POOL = 'pool';

    /** ADR-126 decision 8: a penjoki, a pay-after manual supplier with its own WhatsApp group. */
    public const JOKI_PENJOKI = 'penjoki';

    protected $fillable = [
        'name',
        'slug',
        'driver',
        'funding_mode',
        'joki_role',
        'logo_url',
        'is_active',
        'api_config',
        'balance',
        'currency',
        'last_tested_at',
        'last_test_result',
        'last_price_sync_at',
        'price_sync_alerted_at',
    ];

    /**
     * SUPP-5: never returned in full via any API response — defense
     * in depth so a future controller can't leak it via a plain
     * ->toArray()/->toJson() call without an explicit decision to.
     */
    protected $hidden = [
        'api_config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'api_config' => 'encrypted:array', // SUPP-5: encrypted at rest
        'balance' => 'decimal:2',
        'last_tested_at' => 'datetime',
        'last_price_sync_at' => 'datetime',
        'price_sync_alerted_at' => 'datetime',
    ];

    /**
     * ADR-125 decision 1: an API supplier's driver is its slug
     * (CreateSupplierRequest enforces it), so a row stored without one
     * reads as its slug. Read-side rather than a `creating` hook: seeders
     * run `WithoutModelEvents`, and a raw insert skips events too, so only
     * the read covers every path. A manual supplier always stores
     * `manual` explicitly, so this can never route one to an API adapter.
     */
    protected function driver(): Attribute
    {
        return Attribute::get(fn (?string $value, array $attributes) => filled($value) ? $value : ($attributes['slug'] ?? null));
    }

    /**
     * ADR-125 decision 2: every API-only path (price and product sync,
     * balance refresh, status checks, the staleness alert) asks this,
     * never `api_config` emptiness — a manual supplier keeps its own
     * settings in `api_config` too.
     */
    public function isApiBacked(): bool
    {
        return $this->driver !== self::DRIVER_MANUAL;
    }

    public function scopeApiBacked(Builder $query): Builder
    {
        return $query->where('driver', '!=', self::DRIVER_MANUAL);
    }

    /**
     * ADR-126 decision 15: the pool posts to no group, mints no login
     * link, gets no SLA reminder, is never Delivered and is switched only
     * by the joki switch. Every one of those rules asks this.
     */
    public function isAssignmentPool(): bool
    {
        return $this->joki_role === self::JOKI_POOL;
    }

    public function isPenjoki(): bool
    {
        return $this->joki_role === self::JOKI_PENJOKI;
    }

    public function scopeExceptAssignmentPool(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('joki_role')->orWhere('joki_role', '!=', self::JOKI_POOL));
    }

    /** Created by the 2026-10-23 joki migration; there is exactly one. */
    public static function assignmentPool(): self
    {
        return self::query()->where('joki_role', self::JOKI_POOL)->firstOrFail();
    }

    /** An API supplier with credentials filled in: what price sync, its schedule gate and staleness alert run on. */
    public function isSyncable(): bool
    {
        return $this->isApiBacked() && filled($this->api_config);
    }

    /** ADR-125 decision 18: API suppliers are always prepaid. */
    public function isPayAfter(): bool
    {
        return ! $this->isApiBacked() && $this->funding_mode === self::FUNDING_PAY_AFTER;
    }

    /**
     * ADR-125 decisions 10 and 18: the balance a person should see, in the
     * supplier's currency. An API supplier's is the polled `balance`
     * (null until first polled); a manual supplier has nothing to poll,
     * so its funding ledger is the balance — negative for a pay-after
     * supplier means owed.
     */
    public function displayBalance(): ?float
    {
        if ($this->isApiBacked()) {
            return $this->balance === null ? null : (float) $this->balance;
        }

        return (float) $this->supplierLedgerBalance();
    }

    /**
     * ADR-125 decision 3: the one rule for this supplier's Pending orders,
     * read by ReconcilePendingDeliveriesCommand and DashboardService so the
     * two can't disagree. An API supplier is polled when stale and aged
     * out to NeedsReview (per-supplier `api_config` overrides, else the
     * `delivery_reconciliation` config). A manual supplier is never polled
     * or aged out — a person confirms it — and is stale after its reply SLA.
     *
     * @return array{polls: bool, stale_minutes: int, max_age_days: ?int, max_hours: ?int}
     */
    public function pendingPolicy(): array
    {
        $config = $this->api_config ?? [];

        if (! $this->isApiBacked()) {
            return [
                'polls' => false,
                'stale_minutes' => (int) ($config['reply_sla_minutes'] ?? self::DEFAULT_MANUAL_SLA_MINUTES),
                'max_age_days' => null,
                'max_hours' => null,
            ];
        }

        return self::defaultPendingPolicy($config);
    }

    /**
     * The API-supplier policy, also used for an order with no supplier row.
     *
     * @return array{polls: bool, stale_minutes: int, max_age_days: ?int, max_hours: ?int}
     */
    public static function defaultPendingPolicy(array $config = []): array
    {
        return [
            'polls' => true,
            'stale_minutes' => (int) ($config['pending_stale_minutes'] ?? config('services.delivery_reconciliation.pending_stale_minutes')),
            'max_age_days' => (int) ($config['max_reconcile_age_days'] ?? config('services.delivery_reconciliation.max_reconcile_age_days')),
            'max_hours' => (int) ($config['pending_max_hours'] ?? config('services.delivery_reconciliation.pending_max_hours')),
        ];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(SupplierTransfer::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class);
    }

    /**
     * ADR-083 decision 6: the ledger's own view of this supplier's balance,
     * in the supplier's currency — `SUM(supplier_ledger_entries.amount)`,
     * never cached. Compared against the API-polled `balance` column (still
     * the source other code reads, ADR-069) by the drift check in
     * `app:refresh-supplier-balances`. Not a replacement for `balance`.
     */
    public function supplierLedgerBalance(): string
    {
        // number_format rather than a raw (string) cast: sqlite's SUM()
        // returns a float with no fixed scale (loses trailing zeroes),
        // while real MySQL's DECIMAL SUM() keeps them — this keeps the
        // return value consistent across drivers and matches the
        // decimal(18,4) column's precision.
        return number_format((float) $this->ledgerEntries()->sum('amount'), 4, '.', '');
    }

    /**
     * ADR-083 decision 6 — one place both `RefreshSupplierBalancesCommand`
     * (logs/warns) and `DashboardService::health()` (the amber chip) call,
     * so the two never compute the variance differently. Same fail-safe-
     * to-silent posture as `low_balance_threshold` (ADR-069 decision 13):
     * null unless BOTH a polled `balance` and a configured
     * `api_config['drift_threshold']` exist — an unconfigured supplier
     * never drifts by this check's own definition, it's simply not
     * watched yet. The ledger is never auto-mutated to match — a real
     * variance is rectified by an explicit `MANUAL_ADJUSTMENT`, never by
     * this method.
     *
     * @return array{ledger_balance: float, polled_balance: float, variance: float, threshold: float, is_drifted: bool}|null
     */
    public function fundingDrift(): ?array
    {
        $threshold = $this->api_config['drift_threshold'] ?? null;

        // A manual supplier has no polled balance to drift from (ADR-125 decision 21).
        if (! $this->isApiBacked()) {
            return null;
        }

        if ($this->balance === null || $threshold === null || ! is_numeric($threshold)) {
            return null;
        }

        $ledgerBalance = (float) $this->supplierLedgerBalance();
        $polledBalance = (float) $this->balance;
        $variance = abs($polledBalance - $ledgerBalance);

        return [
            'ledger_balance' => $ledgerBalance,
            'polled_balance' => $polledBalance,
            'variance' => $variance,
            'threshold' => (float) $threshold,
            'is_drifted' => $variance > (float) $threshold,
        ];
    }
}
