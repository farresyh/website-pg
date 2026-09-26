<?php

namespace Tests\Feature\Database;

use App\Models\Order;
use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-074 addendum, 2026-09-26 (code-review follow-up): the
 * 2026_09_26_120000_add_idempotency_scope_to_orders_table migration's
 * down() must refuse to restore the old bare-column unique constraint once
 * two different resellers actually share a checkout_idempotency_key — the
 * exact state this migration exists to allow — rather than let the final
 * statement throw mid-rollback and leave the table with idempotency_scope
 * already dropped and no unique constraint restored either way.
 */
class IdempotencyScopeMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_26_120000_add_idempotency_scope_to_orders_table.php');
    }

    public function test_down_refuses_to_roll_back_once_two_resellers_share_a_key(): void
    {
        $ledger = app(LedgerService::class);
        $resellerA = Reseller::query()->create(['business_name' => 'A', 'is_active' => true]);
        $resellerB = Reseller::query()->create(['business_name' => 'B', 'is_active' => true]);
        $ledger->openAccount(LedgerOwnerType::ResellerWallet, $resellerA->id);
        $ledger->openAccount(LedgerOwnerType::ResellerWallet, $resellerB->id);

        Order::factory()->create([
            'wallet_reseller_id' => $resellerA->id,
            'checkout_idempotency_key' => 'shared-key-across-resellers',
        ]);
        Order::factory()->create([
            'wallet_reseller_id' => $resellerB->id,
            'checkout_idempotency_key' => 'shared-key-across-resellers',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot roll back/');

        $this->migration()->down();
    }

    public function test_down_succeeds_when_no_cross_reseller_collision_exists(): void
    {
        Order::factory()->create(['checkout_idempotency_key' => 'a-normal-guest-order-key']);

        $this->migration()->down();

        $this->assertFalse(collect(Schema::getColumnListing('orders'))->contains('idempotency_scope'));
    }
}
