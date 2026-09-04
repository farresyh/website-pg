<?php

namespace Tests\Feature\Services\Reseller;

use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Services\Ledger\InsufficientBalanceException;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Pricing\PricingBasis;
use App\Services\Reseller\NoResellerTierAssignedException;
use App\Services\Reseller\ResellerInactiveException;
use App\Services\Reseller\ResellerOrderPlacementRequest;
use App\Services\Reseller\ResellerOrderPlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-073 decision 4: the internal order-placement contract both the
 * Reseller API (PR-E) and Reseller Bot (PR-F) will call.
 */
class ResellerOrderPlacementServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeReseller(float $markupPercent = 5): Reseller
    {
        $this->primaryAffiliate();
        $tier = ResellerTier::query()->create([
            'name' => 'Gold', 'markup_percent' => $markupPercent, 'is_active' => true, 'sort_order' => 1,
        ]);
        $reseller = Reseller::query()->create([
            'business_name' => 'Acme Reseller', 'reseller_tier_id' => $tier->id, 'is_active' => true,
        ]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    private function request(string $idempotencyKey = 'test-key-1'): ResellerOrderPlacementRequest
    {
        return new ResellerOrderPlacementRequest(
            playerId: '123456789',
            serverId: '1001',
            costPriceSen: 900,
            standardSellingPriceSen: 900,
            idempotencyKey: $idempotencyKey,
        );
    }

    public function test_places_a_wallet_order_debits_the_wallet_and_dispatches_fulfillment(): void
    {
        Queue::fake();
        $reseller = $this->makeReseller(markupPercent: 5);
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 10000, 'wallet_topup');

        $order = app(ResellerOrderPlacementService::class)->placeOrder($reseller, $this->request());

        // 900 * 1.05 = 945
        $this->assertSame(945, $order->selling_price);
        $this->assertSame(945, $order->final_amount);
        $this->assertSame(45, $order->platform_profit);
        $this->assertSame(0, $order->affiliate_profit);
        $this->assertSame(PricingBasis::ResellerWallet, $order->pricing_basis);
        $this->assertSame('wallet', $order->payment_method);
        $this->assertSame($reseller->id, $order->wallet_reseller_id);
        $this->assertSame($this->primaryAffiliate()->id, $order->affiliate_id);
        $this->assertSame('paid', $order->payment_status->value);

        $this->assertSame(10000 - 945, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
        $this->assertDatabaseHas('ledger_entries', [
            'owner_type' => 'reseller_wallet', 'owner_id' => $reseller->id,
            'type' => 'wallet_debit', 'amount' => -945, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);

        Queue::assertPushed(FulfillOrderJob::class, fn ($job) => $job->order->id === $order->id);
    }

    public function test_deactivated_reseller_cannot_place_an_order(): void
    {
        $reseller = $this->makeReseller();
        $reseller->update(['is_active' => false]);

        $this->expectException(ResellerInactiveException::class);
        app(ResellerOrderPlacementService::class)->placeOrder($reseller, $this->request());
    }

    public function test_reseller_with_no_tier_cannot_place_an_order(): void
    {
        $this->primaryAffiliate();
        $reseller = Reseller::query()->create(['business_name' => 'No Tier', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        $this->expectException(NoResellerTierAssignedException::class);
        app(ResellerOrderPlacementService::class)->placeOrder($reseller, $this->request());
    }

    public function test_insufficient_balance_rejects_before_creating_an_order(): void
    {
        $reseller = $this->makeReseller();
        // No top-up at all — balance 0, price 945.

        try {
            app(ResellerOrderPlacementService::class)->placeOrder($reseller, $this->request());
            $this->fail('Expected InsufficientBalanceException.');
        } catch (InsufficientBalanceException) {
            // expected
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
    }

    public function test_a_repeated_idempotency_key_is_a_no_op_replay_never_a_second_debit(): void
    {
        Queue::fake();
        $reseller = $this->makeReseller();
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 10000, 'wallet_topup');

        $service = app(ResellerOrderPlacementService::class);
        $first = $service->placeOrder($reseller, $this->request('same-key'));
        $second = $service->placeOrder($reseller, $this->request('same-key'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(10000 - 945, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
    }
}
