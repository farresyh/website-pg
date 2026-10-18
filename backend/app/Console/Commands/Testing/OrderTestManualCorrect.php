<?php

namespace App\Console\Commands\Testing;

use App\Models\Order;
use App\Services\Fulfillment\ManualDeliveryService;
use App\Services\Order\InvalidOrderTransitionException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Test-only, same pattern as OrderTestSettle: ADR-125 decision 42's
 * Failed → Delivered correction in its own OS process, so it races Issue
 * Voucher (or a second correction) on the real order row lock.
 */
#[Signature('app:order-test-manual-correct {orderId} {resultFile}')]
#[Description('Test-only: attempt a manual Failed→Delivered correction and write the outcome to a result file.')]
class OrderTestManualCorrect extends Command
{
    public function handle(ManualDeliveryService $manual): int
    {
        $order = Order::query()->findOrFail((int) $this->argument('orderId'));
        $resultFile = $this->argument('resultFile');

        try {
            $manual->correctFailedToDelivered($order, null, 'concurrency test', 'Race Admin');
            file_put_contents($resultFile, 'success');
        } catch (ValidationException|InvalidOrderTransitionException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
