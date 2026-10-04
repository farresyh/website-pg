<?php

namespace App\Console\Commands\Testing;

use App\Models\Order;
use App\Services\Payment\OrderPaymentOutcomeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * OrderPaymentOutcomeConcurrencyTest so a Paid and a Failed gateway
 * answer race OrderPaymentOutcomeService for the same order (item 63).
 */
#[Signature('app:payment-test-apply-outcome {orderId} {status} {amountSen} {resultFile}')]
#[Description('Test-only: apply a Paid or Failed gateway answer to an order and write the outcome to a result file.')]
class PaymentTestApplyOutcome extends Command
{
    public function handle(OrderPaymentOutcomeService $outcomes): int
    {
        try {
            $order = Order::query()->findOrFail((int) $this->argument('orderId'));

            $outcome = $this->argument('status') === 'paid'
                ? $outcomes->applyPaid($order, (int) $this->argument('amountSen'))
                : $outcomes->applyFailed($order);

            file_put_contents($this->argument('resultFile'), $outcome->name);
        } catch (\Throwable $e) {
            file_put_contents($this->argument('resultFile'), 'error: '.get_class($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
