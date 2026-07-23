<?php

namespace App\Console\Commands\Smoke;

use App\Services\Supplier\Gamevion\GamevionAdapter;
use App\Services\Supplier\SupplierResponse;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Manual verification tool, not part of the automated suite — the
 * whole point is hitting the real Gamevion API to confirm assumptions
 * GamevionAdapterTest can't (real error-body shapes for 400/409/422,
 * IP-whitelisting behavior, etc.). Read-only calls only (checkBalance,
 * listProducts) — never createOrder, to avoid any risk of a real
 * order even in sandbox mode.
 */
#[Signature('app:gamevion-smoke-test')]
#[Description('Manually verify GamevionAdapter against the real Gamevion API: checks balance and lists products.')]
class GamevionSmokeTest extends Command
{
    public function handle(): int
    {
        $config = config('services.gamevion');

        if (blank($config['bearer_token'] ?? null) || blank($config['api_key'] ?? null)) {
            $this->error('GAMEVION_BEARER_TOKEN / GAMEVION_API_KEY not set in .env — nothing to test.');

            return self::FAILURE;
        }

        $adapter = new GamevionAdapter(
            baseUrl: $config['base_url'],
            bearerToken: $config['bearer_token'],
            apiKey: $config['api_key'],
            sandbox: (bool) $config['sandbox'],
        );

        $this->info('Checking balance...');
        $balance = $adapter->checkBalance();
        $this->printResult('checkBalance', $balance);

        $this->info('Listing products...');
        $products = $adapter->listProducts();
        $this->printResult('listProducts', $products);

        return $balance->success && $products->success ? self::SUCCESS : self::FAILURE;
    }

    private function printResult(string $label, SupplierResponse $result): void
    {
        if ($result->success) {
            $this->info("[{$label}] success:");
            $this->line(json_encode($result->data, JSON_PRETTY_PRINT));
        } else {
            $this->error("[{$label}] failed: [{$result->errorCode}] {$result->errorMessage}");
        }
    }
}
