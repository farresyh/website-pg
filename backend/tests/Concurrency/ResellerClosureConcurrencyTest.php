<?php

namespace Tests\Concurrency;

use App\Models\AdminUser;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\Supplier;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-131 decisions 12 and 16: closing an account takes the reseller's `ledger_accounts` mutex
 * first, so an order placed at the same moment either lands before the closure (and is then
 * counted) or finds an empty balance; and two closures at once debit once.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class ResellerClosureConcurrencyTest extends TestCase
{
    // Not RefreshDatabase: the spawned processes must see the committed rows.
    use DatabaseMigrations;

    /** @return array<string, string> */
    private function env(): array
    {
        return [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox', 'DB_USERNAME' => 'kerox', 'DB_PASSWORD' => 'kerox', 'QUEUE_CONNECTION' => 'sync',
        ];
    }

    private function newReseller(int $balanceSen): Reseller
    {
        $this->primaryAffiliate();
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 1]);
        $reseller = Reseller::query()->create(['business_name' => 'Closure Race', 'reseller_tier_id' => $tier->id, 'is_active' => true]);
        $ledger = app(LedgerService::class);
        $ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        $ledger->credit(LedgerOwnerType::ResellerWallet, $reseller->id, $balanceSen, 'wallet_topup');

        return $reseller;
    }

    /** @return list<string> */
    private function race(array $commandA, array $commandB, string $fileA, string $fileB): array
    {
        $a = Process::path(base_path())->env($this->env())->start($commandA);
        $b = Process::path(base_path())->env($this->env())->start($commandB);
        $a->wait();
        $b->wait();
        $out = [file_get_contents($fileA), file_get_contents($fileB)];
        unlink($fileA);
        unlink($fileB);

        return $out;
    }

    public function test_an_order_placed_during_a_closure_is_never_lost_between_the_refund_and_the_balance(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Closure Race Supplier', 'slug' => 'closure-race-supplier', 'api_config' => [], 'currency' => 'MYR']);
        $reseller = $this->newReseller(1000);
        $admin = AdminUser::factory()->superAdmin()->create();
        $files = [tempnam(sys_get_temp_dir(), 'closure_a_'), tempnam(sys_get_temp_dir(), 'closure_b_')];

        $outcomes = $this->race(
            [PHP_BINARY, 'artisan', 'app:reseller-closure-test-close', (string) $reseller->id, (string) $admin->id, $files[0]],
            [PHP_BINARY, 'artisan', 'app:reseller-order-test-place', (string) $reseller->id, (string) $supplier->id, '900', '900', 'closure-race-order', $files[1]],
            $files[0],
            $files[1],
        );

        $balance = app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id);
        $orders = Order::query()->where('wallet_reseller_id', $reseller->id)->count();
        $refunds = LedgerEntry::query()->where('type', 'wallet_closure_refund')->get();
        $closed = Reseller::onlyTrashed()->whereKey($reseller->id)->exists();
        $json = json_encode($outcomes);

        // Money is conserved whichever came first: what is left plus what was refunded plus what
        // the order cost is exactly the RM10.00 that was there, and the balance never goes negative.
        $this->assertGreaterThanOrEqual(0, $balance, $json);
        $this->assertSame(1000, $balance + (-1 * (int) $refunds->sum('amount')) + $orders * 945, $json);
        // If it closed, nothing was left behind in the balance; if it was refused, the order is the reason.
        $closed ? $this->assertSame(0, $balance, $json) : $this->assertSame(1, $orders, $json);
    }

    public function test_two_simultaneous_closures_refund_once(): void
    {
        $reseller = $this->newReseller(150_000);
        $admin = AdminUser::factory()->superAdmin()->create();
        $files = [tempnam(sys_get_temp_dir(), 'closure_c_'), tempnam(sys_get_temp_dir(), 'closure_d_')];
        $command = fn (string $file) => [PHP_BINARY, 'artisan', 'app:reseller-closure-test-close', (string) $reseller->id, (string) $admin->id, $file];

        $outcomes = $this->race($command($files[0]), $command($files[1]), $files[0], $files[1]);

        $this->assertCount(1, array_filter($outcomes, fn ($r) => str_starts_with($r, 'closed:')), json_encode($outcomes));
        $this->assertCount(1, array_filter($outcomes, fn ($r) => str_starts_with($r, 'refused:')), json_encode($outcomes));
        $this->assertSame(1, LedgerEntry::query()->where('type', 'wallet_closure_refund')->count());
        $this->assertSame(0, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
    }
}
