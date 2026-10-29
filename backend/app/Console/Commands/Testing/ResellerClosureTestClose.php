<?php

namespace App\Console\Commands\Testing;

use App\Models\Reseller;
use App\Services\Reseller\ResellerClosureService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * tests/Concurrency/ResellerClosureConcurrencyTest.php so a closure races for real against an
 * order placement (or a second closure) on the same reseller's `ledger_accounts` row.
 */
#[Signature('app:reseller-closure-test-close {resellerId} {adminId} {resultFile}')]
#[Description('Test-only: attempt to close a reseller account and write the outcome to a result file.')]
class ResellerClosureTestClose extends Command
{
    public function handle(ResellerClosureService $closures): int
    {
        $reseller = Reseller::query()->findOrFail((int) $this->argument('resellerId'));

        try {
            $refunded = $closures->close($reseller, (int) $this->argument('adminId'), now('Asia/Kuala_Lumpur')->toDateString(), 'RACE-REF');
            file_put_contents($this->argument('resultFile'), 'closed:'.$refunded);
        } catch (ValidationException $e) {
            file_put_contents($this->argument('resultFile'), 'refused:'.json_encode($e->errors()));
        }

        return self::SUCCESS;
    }
}
