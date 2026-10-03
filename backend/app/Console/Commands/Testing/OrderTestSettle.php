<?php

namespace App\Console\Commands\Testing;

use App\Models\Order;
use App\Services\Fulfillment\OrderFulfillmentException;
use App\Services\Fulfillment\OrderSettlementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

#[Signature('app:order-test-settle {orderId} {adminId} {resultFile}')]
#[Description('Test-only: attempt to compensate a single order via OrderSettlementService and write the outcome to a result file.')]
class OrderTestSettle extends Command
{
    public function handle(OrderSettlementService $settlement): int
    {
        $order = Order::query()->findOrFail((int) $this->argument('orderId'));
        $resultFile = $this->argument('resultFile');

        try {
            $settlement->settle($order, (int) $this->argument('adminId'));
            file_put_contents($resultFile, 'success');
        } catch (OrderFulfillmentException|UniqueConstraintViolationException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
