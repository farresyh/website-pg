<?php

namespace App\Console\Commands\Supplier;

use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierConfigSchema;
use App\Services\Supplier\SupplierNotConfiguredException;
use App\Services\Supplier\UnsupportedSupplierException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-069 decision 12 — `Supplier.balance` is otherwise only ever
 * refreshed by an admin clicking "Refresh Balance" on
 * /middleware/suppliers, so the Dashboard Health figure can be days
 * stale. This runs `checkBalance()` for every active, fully-configured
 * supplier on a daily schedule (routes/console.php) and writes the
 * result, and emits a `Log::warning` when a balance drops below the
 * supplier's own `api_config['low_balance_threshold']` (absent = no
 * warning — fail-safe to silent, never to false alarms).
 *
 * Calling the adapter directly from a scheduled command is fine here:
 * `checkBalance()` is a read-only supplier call with no side effect
 * (unlike order fulfillment, which ADR-014 keeps queued), and the same
 * footing app:*-smoke-test already uses. `CircuitBreakingSupplierAdapter`
 * + `TransientFailureRetryPolicy` still wrap the call.
 *
 * One supplier failing (unconfigured, breaker open, network) is logged
 * and skipped — never fatal to the rest of the sweep.
 */
#[Signature('app:refresh-supplier-balances')]
#[Description('Refresh Supplier.balance for every active, configured supplier and warn on a low balance.')]
class RefreshSupplierBalancesCommand extends Command
{
    public function handle(SupplierAdapterFactory $adapters): int
    {
        $suppliers = Supplier::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Supplier $s) => SupplierConfigSchema::missingKeys($s->slug, $s->api_config ?? []) === []);

        if ($suppliers->isEmpty()) {
            $this->info('No active, fully-configured supplier to refresh.');

            return self::SUCCESS;
        }

        foreach ($suppliers as $supplier) {
            try {
                $adapter = $adapters->make($supplier->slug);
            } catch (UnsupportedSupplierException|SupplierNotConfiguredException $e) {
                $this->warn("Skipping '{$supplier->slug}': {$e->getMessage()}");

                continue;
            }

            $response = $adapter->checkBalance();

            $update = [
                'last_tested_at' => now(),
                'last_test_result' => $response->success
                    ? 'success'
                    : "failed: [{$response->errorCode}] {$response->errorMessage}",
            ];

            if (! $response->success) {
                $this->error("'{$supplier->slug}' balance check failed: {$response->errorMessage}");
                $supplier->update($update);

                continue;
            }

            $balance = $response->data['balance'] ?? null;

            if ($balance !== null) {
                $update['balance'] = $balance;
            }

            $supplier->update($update);

            $threshold = $supplier->api_config['low_balance_threshold'] ?? null;

            if ($balance !== null && $threshold !== null && is_numeric($threshold) && (float) $balance < (float) $threshold) {
                Log::warning('Supplier balance is low', [
                    'supplier' => $supplier->slug,
                    'balance' => (float) $balance,
                    'threshold' => (float) $threshold,
                    'currency' => $supplier->currency,
                ]);
                $this->warn("'{$supplier->slug}' balance {$balance} {$supplier->currency} is below threshold {$threshold}");
            } else {
                $this->info("'{$supplier->slug}' balance: {$balance} {$supplier->currency}");
            }
        }

        return self::SUCCESS;
    }
}
