<?php

namespace App\Console\Commands\Testing;

use App\Models\Supplier;
use App\Services\Sync\PackagePriceSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Test-only helper, same pattern as LedgerTestWithdraw/VoucherTestRedeem:
 * invoked as a genuinely separate OS process by
 * tests/Concurrency/PendingPriceChangeConcurrencyTest.php so two sync
 * runs evaluate the same package's price swing for real (separate PHP
 * process + separate DB connection each), proving ADR-025 decision #5's
 * idempotency guard under an actual race, not just sequential calls.
 */
#[Signature('app:price-sync-test-evaluate {supplierId} {syncedAt} {resultFile}')]
#[Description('Test-only: run PackagePriceSyncService::apply() once and write the anomaly count to a result file.')]
class PriceSyncTestEvaluate extends Command
{
    public function handle(PackagePriceSyncService $service): int
    {
        $supplier = Supplier::query()->findOrFail($this->argument('supplierId'));
        $syncedAt = Carbon::parse($this->argument('syncedAt'));
        $resultFile = $this->argument('resultFile');

        $result = $service->apply($supplier, $syncedAt, priceSyncRunId: null);

        file_put_contents($resultFile, (string) $result->anomaliesFlagged);

        return self::SUCCESS;
    }
}
