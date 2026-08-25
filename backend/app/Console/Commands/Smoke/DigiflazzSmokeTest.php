<?php

namespace App\Console\Commands\Smoke;

use App\Services\Supplier\Digiflazz\DigiflazzAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Manual verification tool, not part of the automated suite — mirrors
 * GamevionSmokeTest's purpose (confirm assumptions DigiflazzAdapterTest's
 * Http::fake()-based tests can't: real response shapes, live IP
 * whitelist behavior). Unlike GamevionSmokeTest, this DOES call
 * createOrder() — Digiflazz's official test cases (ADR-030 decision 7)
 * are deterministic, side-effect-free sandbox scenarios designed
 * specifically to be called for real, the same way Stripe's test card
 * numbers are. Each ref_id is freshly generated per run so a repeat
 * run never collides with a prior one.
 */
#[Signature('app:digiflazz-smoke-test')]
#[Description('Manually verify DigiflazzAdapter against the real Digiflazz API: balance, catalog, and all 4 official test-case transactions.')]
class DigiflazzSmokeTest extends Command
{
    /** ADR-030 decision 7 — the official prepaid test cases (buyer_sku_code: xld10). */
    private const TEST_CASES = [
        '087800001230' => 'Sukses (immediate)',
        '087800001232' => 'Gagal (immediate)',
        '087800001233' => 'Pending, then callback Sukses',
        '087800001234' => 'Pending, then callback Gagal',
    ];

    public function handle(): int
    {
        $config = config('services.digiflazz');

        if (blank($config['username'] ?? null) || blank($config['api_key'] ?? null)) {
            $this->error('DIGIFLAZZ_USERNAME / DIGIFLAZZ_API_KEY not set in .env — nothing to test.');

            return self::FAILURE;
        }

        $adapter = new DigiflazzAdapter(
            baseUrl: $config['base_url'],
            username: $config['username'],
            apiKey: $config['api_key'],
            testing: (bool) $config['testing'],
            customerNoSeparator: $config['customer_no_separator'],
        );

        $this->info('Checking balance...');
        $balance = $adapter->checkBalance();
        $this->printResult('checkBalance', $balance);

        $this->info('Listing products...');
        $products = $adapter->listProducts();
        $this->printResult('listProducts', $products);

        $allOk = $balance->success && $products->success;

        foreach (self::TEST_CASES as $customerNo => $label) {
            $this->info("Running official test case [{$customerNo}] {$label}...");

            $result = $adapter->createOrder(new SupplierOrderRequest(
                productRef: 'xld10',
                referenceNumber: 'SMOKE-'.Str::upper(Str::random(12)),
                playerId: $customerNo,
            ));

            $this->printResult("createOrder [{$customerNo}]", $result);

            // Not folded into $allOk — a Gagal/Pending outcome here is
            // the EXPECTED result for 3 of these 4 cases, not a real
            // failure of this smoke test.
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    private function printResult(string $label, SupplierResponse $result): void
    {
        if ($result->success || $result->outcome->value === 'pending') {
            $this->info("[{$label}] {$result->outcome->value}:");
            $this->line(json_encode($result->data, JSON_PRETTY_PRINT));
        } else {
            $this->error("[{$label}] {$result->outcome->value}: [{$result->errorCode}] {$result->errorMessage}");
        }
    }
}
