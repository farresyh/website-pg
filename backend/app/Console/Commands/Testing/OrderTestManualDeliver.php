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
 * Test-only: a supplier's `.done` (ManualDeliveryService::confirmDelivered,
 * the bot's channel) in its own OS process, read as the supplier saw the
 * order before the race — so it can race an Assign (ADR-126 decision 18).
 */
#[Signature('app:order-test-manual-deliver {orderId} {supplierId} {resultFile}')]
#[Description('Test-only: attempt a manual Delivered as one supplier and write the outcome to a result file.')]
class OrderTestManualDeliver extends Command
{
    public function handle(ManualDeliveryService $manual): int
    {
        $order = Order::query()->findOrFail((int) $this->argument('orderId'));
        $resultFile = $this->argument('resultFile');

        try {
            $manual->confirmDelivered($order, null, null, 'wa:race', (int) $this->argument('supplierId'), 'supplier_bot');
            file_put_contents($resultFile, 'success');
        } catch (ValidationException|InvalidOrderTransitionException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
