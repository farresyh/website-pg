<?php

namespace Tests\Concurrency;

use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-103 2026-11-07 addendum: only a real process and a real database
 * prove these. A killed worker rolls back what it hadn't committed, so the
 * leg's claim must already be committed when the supplier call starts, and
 * phase 2's lock order must not deadlock with a webhook.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class ComboLegKilledWorkerConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    private const ENV = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'kerox',
        'DB_USERNAME' => 'kerox',
        'DB_PASSWORD' => 'kerox',
    ];

    private function combo(array $overrides = []): Order
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Kill Test Game', 'slug' => 'kill-test-game']);
        $component = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Component', 'denomination' => 100,
            'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'KILL-REF',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 100, 'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => 0]);

        return Order::query()->create(array_merge([
            'placed_via' => 'storefront', 'affiliate_id' => $this->primaryAffiliate()->id, 'order_number' => 'KRS-KILL-'.uniqid(),
            'customer_email' => 'kill@example.com', 'game_id' => $game->id, 'package_id' => $combo->id, 'player_id' => '123456',
            'supplier_id' => null, 'supplier_product_ref' => null, 'cost_price' => 500, 'standard_selling_price' => 600,
            'selling_price' => 700, 'transaction_fee' => 100, 'final_amount' => 800, 'platform_profit' => 200, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    /** Starts a fulfilment that stops inside the supplier call, and waits until it is there. */
    private function holdInsideSupplierCall(Order $order, int $seconds, string $resultFile): InvokedProcess
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'combo_ready_');
        file_put_contents($readyFile, '');

        $process = Process::path(base_path())->env(self::ENV)->timeout(60)->start([
            PHP_BINARY, 'artisan', 'app:order-fulfillment-test-fulfill', (string) $order->id, $resultFile, $readyFile, (string) $seconds,
        ]);

        for ($i = 0; $i < 300 && file_get_contents($readyFile) === ''; $i++) {
            usleep(100_000);
        }

        $reference = file_get_contents($readyFile);
        unlink($readyFile);

        if ($reference === '') {
            $process->signal(SIGKILL);

            throw new RuntimeException('The worker never reached the supplier call: '.$process->wait()->errorOutput());
        }

        return $process;
    }

    private function kill(InvokedProcess $process): void
    {
        $process->signal(SIGKILL);
        $process->wait();
    }

    public function test_a_worker_killed_during_a_first_attempt_leaves_a_durable_claim(): void
    {
        Queue::fake();
        $order = $this->combo();
        $resultFile = tempnam(sys_get_temp_dir(), 'combo_kill_');

        $this->kill($this->holdInsideSupplierCall($order, 30, $resultFile));
        unlink($resultFile);

        $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->sole();
        $this->assertSame(DeliveryStatus::Processing, $leg->status);
        $this->assertStringEndsWith('-L1', $leg->reference_number);
        $attempt = OrderResendAttempt::query()->where('order_delivery_leg_id', $leg->id)->sole();
        $this->assertSame(['initial', 'pending'], [$attempt->attempt_type, $attempt->outcome]);
        $this->assertSame(DeliveryStatus::Processing, $order->fresh()->delivery_status);

        app(OrderFulfillmentService::class)->recoverDeadComboPass($order->fresh());

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
        $this->assertSame(DeliveryStatus::Pending, $leg->fresh()->status);
        $this->assertSame($leg->reference_number, $leg->fresh()->reference_number);
    }

    public function test_a_worker_killed_retrying_a_failed_leg_keeps_the_fresh_reference(): void
    {
        Queue::fake();
        $order = $this->combo(['reference_number' => 'REF-KILL', 'delivery_status' => DeliveryStatus::Failed->value]);
        $component = $order->package->components->first();
        $leg = OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $component->id, 'supplier_id' => $component->supplier_id,
            'leg_number' => 1, 'status' => DeliveryStatus::Failed->value, 'reference_number' => 'REF-KILL-L1', 'selling_price_sen' => 600,
        ]);
        $resultFile = tempnam(sys_get_temp_dir(), 'combo_kill_');

        $this->kill($this->holdInsideSupplierCall($order, 30, $resultFile));
        unlink($resultFile);

        $fresh = $leg->fresh();
        $this->assertSame(DeliveryStatus::Processing, $fresh->status);
        $this->assertStringStartsWith('REF-KILL-L1-', $fresh->reference_number);
        $this->assertSame('retry', OrderResendAttempt::query()->where('order_delivery_leg_id', $leg->id)->value('attempt_type'));
    }

    public function test_a_webhook_for_the_leg_in_flight_waits_instead_of_deadlocking(): void
    {
        Queue::fake();
        $order = $this->combo();
        $workerResult = tempnam(sys_get_temp_dir(), 'combo_worker_');
        $webhookResult = tempnam(sys_get_temp_dir(), 'combo_webhook_');

        $worker = $this->holdInsideSupplierCall($order, 2, $workerResult);
        $leg = OrderDeliveryLeg::query()->where('order_id', $order->id)->sole();

        $webhook = Process::path(base_path())->env(self::ENV)->timeout(60)->start([
            PHP_BINARY, 'artisan', 'app:order-delivery-leg-finalize-test-finalize', (string) $leg->id, $webhookResult, 'success',
        ]);

        $worker->wait();
        $webhook->wait();

        $worked = file_get_contents($workerResult);
        $answered = file_get_contents($webhookResult);
        unlink($workerResult);
        unlink($webhookResult);

        $this->assertSame('success', $worked);
        // Reached the lock and was turned away by status: a deadlock would have left no result at all.
        $this->assertStringStartsWith('failed:', $answered);
        $this->assertSame(DeliveryStatus::Delivered, $leg->fresh()->status);
        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }
}
