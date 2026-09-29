<?php

namespace Tests\Concurrency;

use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * 2026-09-29 audit finding M-7: CheckoutService::requestPayment() had no
 * lock, so two concurrent resume() calls for the same Order (double-
 * click retry, two tabs) could both call the gateway and mint two live
 * CHIP purchases for one order — whichever payment_ref update() landed
 * last silently orphaned the other. Proves two genuinely separate
 * processes racing resume() on the same order call the gateway exactly
 * once.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class CheckoutResumeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_simultaneous_resumes_call_the_gateway_only_once(): void
    {
        $order = Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-RESUME-RACE-1',
            'idempotency_key' => 'idem-resume-race-1',
            'customer_name' => 'Buyer',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ]);

        $callsFile = tempnam(sys_get_temp_dir(), 'checkout_resume_race_calls_');
        $resultFileA = tempnam(sys_get_temp_dir(), 'checkout_resume_race_result_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'checkout_resume_race_result_');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
        ];

        $command = fn (string $resultFile) => [
            PHP_BINARY, 'artisan', 'app:checkout-test-resume', (string) $order->id, $callsFile, $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command($resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command($resultFileB));

        $processA->wait();
        $processB->wait();

        $resultA = file_get_contents($resultFileA);
        $resultB = file_get_contents($resultFileB);
        $calls = file_exists($callsFile) ? file($callsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        unlink($resultFileA);
        unlink($resultFileB);
        @unlink($callsFile);

        $this->assertStringNotContainsString('error:', $resultA, "Process A errored: {$resultA}");
        $this->assertStringNotContainsString('error:', $resultB, "Process B errored: {$resultB}");

        $this->assertCount(1, $calls, 'The gateway must be called exactly once across both racing resume() calls.');
        $this->assertSame($resultA, $resultB, 'Both processes must resolve to the same payment_ref.');
        $this->assertSame('pr-resume-race-test', $order->fresh()->payment_ref);
    }
}
