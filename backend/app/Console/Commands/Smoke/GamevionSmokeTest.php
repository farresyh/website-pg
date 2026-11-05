<?php

namespace App\Console\Commands\Smoke;

use App\Models\Supplier;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Manual check of GamevionAdapter against the real gamevion.cloud API,
 * through the `gamevion` Supplier row's own config (ADR-137 decision 11).
 * Free calls only: the balance and the catalog. `--order` sends one order
 * and only while the balance is exactly 0, where Gamevion must answer 402
 * `insufficient_balance` and charge nothing; it never runs with money in
 * the account.
 */
#[Signature('app:gamevion-smoke-test {--order= : game:sku to try once at a zero balance, expecting 402} {--userid=} {--serverid=}')]
#[Description('Check GamevionAdapter against the real Gamevion API: balance, catalog, and optionally a 402 order at a zero balance.')]
class GamevionSmokeTest extends Command
{
    public function handle(SupplierAdapterFactory $adapters): int
    {
        $supplier = Supplier::query()->where('slug', 'gamevion')->first();

        if ($supplier === null) {
            $this->error('No gamevion supplier row.');

            return self::FAILURE;
        }

        $adapter = $adapters->for($supplier);

        $balance = $adapter->checkBalance();
        $this->printResult('checkBalance', $balance);

        $products = $adapter->listProducts();
        $this->printResult('listProducts', $products, fn (array $items) => count($items).' products, e.g. '.json_encode(array_slice($items, 0, 3)));

        if (! $balance->success || ! $products->success) {
            return self::FAILURE;
        }

        if ($this->option('order') === null) {
            return self::SUCCESS;
        }

        if ((float) $balance->data['balance'] !== 0.0) {
            $this->error('Refusing --order: the balance is not 0, so the order would be real.');

            return self::FAILURE;
        }

        $order = $adapter->createOrder(new SupplierOrderRequest(
            productRef: (string) $this->option('order'),
            referenceNumber: 'SMOKE-'.Str::ulid(),
            playerId: (string) $this->option('userid'),
            serverId: $this->option('serverid'),
        ));
        $this->printResult('createOrder at zero balance', $order);

        return $order->errorCode === 'insufficient_balance' ? self::SUCCESS : self::FAILURE;
    }

    private function printResult(string $label, SupplierResponse $result, ?callable $summary = null): void
    {
        if ($result->success) {
            $this->info("[{$label}] success: ".($summary ? $summary($result->data) : json_encode($result->data)));
        } else {
            $this->error("[{$label}] failed: [{$result->errorCode}] {$result->errorMessage}");
        }
    }
}
