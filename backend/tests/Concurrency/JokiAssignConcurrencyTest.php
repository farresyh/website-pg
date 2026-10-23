<?php

namespace Tests\Concurrency;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\JokiAssignment;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\WhatsAppGroup;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Pricing\PricingBasis;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-126 decisions 16, 18, 19: Assign takes the order lock, then the
 * penjoki's lock. It races the previous penjoki's `.done` (finalize
 * re-checks the supplier under the order lock) and a penjoki switch-off
 * (which takes the same penjoki lock). Exactly one wins each race, and
 * the payout is owed to the penjoki who actually holds the order.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class JokiAssignConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    private function penjoki(string $slug): Supplier
    {
        $supplier = Supplier::query()->create([
            'name' => "Penjoki {$slug}", 'slug' => "penjoki-{$slug}", 'driver' => Supplier::DRIVER_MANUAL,
            'funding_mode' => Supplier::FUNDING_PAY_AFTER, 'api_config' => [], 'currency' => 'MYR',
            'joki_role' => Supplier::JOKI_PENJOKI, 'is_active' => true,
        ]);
        WhatsAppGroup::query()->create(['owner_type' => 'supplier', 'owner_id' => $supplier->id, 'whatsapp_group_id' => "{$slug}@g.us"]);

        return $supplier;
    }

    private function pendingJokiOrder(Supplier $on): Order
    {
        $pool = Supplier::assignmentPool();
        $package = Package::query()->create([
            'game_id' => Game::query()->create(['name' => 'Joki MLBB', 'slug' => 'joki-mlbb', 'is_active' => true, 'is_joki' => true])->id,
            'name' => 'Joki Ranked', 'cost_price' => 0, 'standard_selling_price' => 0,
            'supplier_id' => $pool->id, 'supplier_package_ref' => 'JOKI-RACE',
        ]);

        return Order::query()->create(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-JOKIRACE001',
            'customer_email' => 'race@example.com',
            'player_id' => 'race@example.com',
            'game_id' => $package->game_id,
            'package_id' => $package->id,
            'supplier_id' => $on->id,
            'supplier_product_ref' => 'JOKI-RACE',
            'reference_number' => 'REF-JOKIRACE',
            'pricing_basis' => PricingBasis::Fixed->value,
            'cost_price' => 7000,
            'standard_selling_price' => 7000,
            'selling_price' => 7000,
            'transaction_fee' => 100,
            'final_amount' => 7100,
            'platform_profit' => 0,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
        ]);
    }

    /** @param  list<list<string>>  $commands */
    private function race(array $commands): array
    {
        $env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox', 'DB_USERNAME' => 'kerox', 'DB_PASSWORD' => 'kerox',
            // The group post is queued, never sent, from a race process.
            'QUEUE_CONNECTION' => 'null',
        ];
        $files = array_map(fn () => tempnam(sys_get_temp_dir(), 'joki_race_'), $commands);
        $processes = array_map(
            fn (array $command, string $file) => Process::path(base_path())->env($env)->start([PHP_BINARY, 'artisan', ...$command, $file]),
            $commands,
            $files,
        );
        array_map(fn ($p) => $p->wait(), $processes);

        $outcomes = array_map(fn (string $f) => file_get_contents($f), $files);
        array_map('unlink', $files);

        return $outcomes;
    }

    private function drawdownsFor(Order $order)
    {
        return SupplierLedgerEntry::query()->where('reference_type', 'order')->where('reference_id', $order->id)->get();
    }

    public function test_a_reassignment_racing_the_old_penjokis_done_pays_exactly_the_one_holding_the_order(): void
    {
        $amir = $this->penjoki('amir');
        $bala = $this->penjoki('bala');
        $order = $this->pendingJokiOrder($amir);

        $outcomes = $this->race([
            ['app:order-test-manual-deliver', (string) $order->id, (string) $amir->id],
            ['app:order-test-joki-assign', (string) $order->id, (string) $bala->id],
        ]);

        $this->assertCount(1, array_filter($outcomes, fn ($r) => $r === 'success'), json_encode($outcomes));
        $fresh = $order->fresh();
        $drawdowns = $this->drawdownsFor($order);

        if ($fresh->delivery_status === DeliveryStatus::Delivered) {
            // `.done` won: Amir played it and is owed it; nobody reassigned it.
            $this->assertSame($amir->id, $fresh->supplier_id);
            $this->assertSame(0, JokiAssignment::query()->count());
            $this->assertSame([$amir->id], $drawdowns->pluck('supplier_id')->all());
            $this->assertSame(7000, $fresh->real_cost_price_sen);
        } else {
            // Assign won: Bala holds it, Amir's `.done` was refused, nothing is owed yet.
            $this->assertSame(DeliveryStatus::Pending, $fresh->delivery_status);
            $this->assertSame($bala->id, $fresh->supplier_id);
            $this->assertCount(0, $drawdowns);
        }
    }

    public function test_an_assignment_racing_a_switch_off_never_leaves_work_with_an_inactive_penjoki(): void
    {
        $amir = $this->penjoki('amir');
        $order = $this->pendingJokiOrder(Supplier::assignmentPool());
        $admin = AdminUser::factory()->create();

        $outcomes = $this->race([
            ['app:order-test-joki-assign', (string) $order->id, (string) $amir->id],
            ['app:supplier-test-switch-off', (string) $amir->id, (string) $admin->id],
        ]);

        $this->assertCount(1, array_filter($outcomes, fn ($r) => $r === 'success'), json_encode($outcomes));
        $fresh = $order->fresh();

        if ($amir->fresh()->is_active) {
            $this->assertSame($amir->id, $fresh->supplier_id);
        } else {
            $this->assertSame(Supplier::assignmentPool()->id, $fresh->supplier_id);
        }
    }
}
