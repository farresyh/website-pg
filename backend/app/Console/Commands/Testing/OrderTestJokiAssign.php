<?php

namespace App\Console\Commands\Testing;

use App\Models\Order;
use App\Services\Joki\JokiAssignmentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Test-only, same pattern as OrderTestManualCorrect: ADR-126 decision 16's
 * Assign in its own OS process, so it races a `.done` or a penjoki
 * switch-off on the real row locks.
 */
#[Signature('app:order-test-joki-assign {orderId} {supplierId} {resultFile}')]
#[Description('Test-only: attempt a joki Assign and write the outcome to a result file.')]
class OrderTestJokiAssign extends Command
{
    public function handle(JokiAssignmentService $assignments): int
    {
        $order = Order::query()->findOrFail((int) $this->argument('orderId'));
        $resultFile = $this->argument('resultFile');

        try {
            $assignments->assign($order, (int) $this->argument('supplierId'), 'Race Admin');
            file_put_contents($resultFile, 'success');
        } catch (ValidationException $e) {
            file_put_contents($resultFile, 'failed:'.json_encode($e->errors()));
        }

        return self::SUCCESS;
    }
}
