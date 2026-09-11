<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'logo_url',
        'is_active',
        'api_config',
        'balance',
        'currency',
        'last_tested_at',
        'last_test_result',
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
    ];

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
