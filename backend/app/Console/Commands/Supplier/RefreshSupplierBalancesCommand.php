<?php

namespace App\Console\Commands\Supplier;

use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierBufferAlert;
use App\Services\Supplier\SupplierConfigSchema;
use App\Services\Supplier\SupplierNotConfiguredException;
use App\Services\Supplier\UnsupportedSupplierException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * ADR-069 decision 12 — `Supplier.balance` is otherwise only ever
 * refreshed by an admin clicking "Refresh Balance" on
 * /middleware/suppliers, so the Dashboard Health figure can be days
 * stale. This runs `checkBalance()` for every active, fully-configured
 * supplier on a daily schedule (routes/console.php) and writes the
 * result, then runs the buffer check for every active API supplier
 * (ADR-115 2026-11-05 addendum): a log line, and one email a day, while a
 * balance is below what it needs to last the coming window.
 *
 * Calling the adapter directly from a scheduled command is fine here:
 * `checkBalance()` is a read-only supplier call with no side effect
 * (unlike order fulfillment, which ADR-014 keeps queued), and the same
 * footing app:*-smoke-test already uses. `CircuitBreakingSupplierAdapter`
 * + `TransientFailureRetryPolicy` still wrap the call.
 *
 * One supplier failing (unconfigured, breaker open, unreachable) is
 * recorded and skipped — never fatal to the rest of the sweep. An
 * unreachable supplier is an ordinary failed response (the breaker
 * decorator returns it, item 130), so the task stays green. Any other
 * exception is a bug: that supplier is skipped, the rest still refresh,
 * and the task exits FAILURE so ADR-122's heartbeat turns health red.
 */
#[Signature('app:refresh-supplier-balances')]
#[Description('Refresh Supplier.balance for every active, configured supplier and check each against its buffer.')]
class RefreshSupplierBalancesCommand extends Command
{
    public function handle(SupplierAdapterFactory $adapters, SupplierBufferAlert $buffers): int
    {
        // ADR-125 decision 2: a manual supplier has no balance to poll; its
        // balance is its funding ledger (Supplier::displayBalance()).
        $active = Supplier::query()->where('is_active', true)->apiBacked()->get();

        // ADR-069 stress-test Q5 — log every skipped supplier + its
        // missing keys. `customer_no_separator` is REQUIRED for
        // digiflazz but shows a default-looking placeholder in the Edit
        // form; a supplier left partially configured would otherwise
        // drop out of the daily refresh silently.
        $suppliers = $active->filter(function (Supplier $s) {
            $missing = SupplierConfigSchema::missingKeys($s->driver, $s->api_config ?? []);

            if ($missing !== []) {
                Log::info('refresh-supplier-balances: skipping supplier — not fully configured', [
                    'supplier' => $s->slug,
                    'missing' => $missing,
                ]);
                $this->warn("Skipping '{$s->slug}': missing ".implode(', ', $missing));

                return false;
            }

            return true;
        });

        if ($suppliers->isEmpty()) {
            $this->info('No active, fully-configured supplier to refresh.');
        }

        $crashed = false;

        foreach ($suppliers as $supplier) {
            try {
                $this->refresh($supplier, $adapters);
            } catch (Throwable $e) {
                $crashed = true;
                report($e);
                $supplier->update([
                    'last_tested_at' => now(),
                    'last_test_result' => 'failed: [ERROR] '.Str::limit($e->getMessage(), 200),
                ]);
                $this->error("'{$supplier->slug}' balance refresh crashed: {$e->getMessage()}");
            }
        }

        // ADR-115 2026-11-05 addendum, decision 6: the daily check, even for a
        // supplier whose balance did not move (Thursday widens the window).
        foreach ($active as $supplier) {
            try {
                $buffers->check($supplier->fresh());
            } catch (Throwable $e) {
                $crashed = true;
                report($e);
                $this->error("'{$supplier->slug}' buffer check crashed: {$e->getMessage()}");
            }
        }

        return $crashed ? self::FAILURE : self::SUCCESS;
    }

    private function refresh(Supplier $supplier, SupplierAdapterFactory $adapters): void
    {
        try {
            $adapter = $adapters->for($supplier);
        } catch (UnsupportedSupplierException|SupplierNotConfiguredException $e) {
            $this->warn("Skipping '{$supplier->slug}': {$e->getMessage()}");

            return;
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

            return;
        }

        $balance = $response->data['balance'] ?? null;
        $supplier->recordPolledBalance($balance === null ? null : (float) $balance, $update);
        $this->info("'{$supplier->slug}' balance: {$balance} {$supplier->currency}");

        // ADR-083 decision 6 — compares the just-refreshed
        // `balance` against the funding ledger's own sum; never
        // auto-corrects either side, only warns. `fundingDrift()`
        // returns null when `drift_threshold` isn't configured
        // (fail-safe to silent, same posture as low-balance above).
        $drift = $supplier->fundingDrift();

        if ($drift !== null && $drift['is_drifted']) {
            Log::warning('Supplier funding ledger has drifted from the polled balance', [
                'supplier' => $supplier->slug,
                'polled_balance' => $drift['polled_balance'],
                'ledger_balance' => $drift['ledger_balance'],
                'variance' => $drift['variance'],
                'threshold' => $drift['threshold'],
                'currency' => $supplier->currency,
            ]);
            $this->warn("'{$supplier->slug}' funding ledger drifted: polled {$drift['polled_balance']} vs ledger {$drift['ledger_balance']} {$supplier->currency} (variance {$drift['variance']}, threshold {$drift['threshold']})");
        }
    }
}
