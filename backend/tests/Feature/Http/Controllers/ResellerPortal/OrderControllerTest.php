<?php

namespace Tests\Feature\Http\Controllers\ResellerPortal;

use App\Models\AffiliateUser;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-072 decision 5 / PR-G planning addendum decision 2: read-only
 * order history for a Reseller (wallet) portal account.
 */
class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(Reseller $reseller): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $reseller->id,
            'name' => 'Reseller Staff', 'email' => 'staff+'.$reseller->id.'@wallet-reseller.test',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    private function reseller(string $name = 'Wallet Reseller'): Reseller
    {
        return Reseller::query()->create(['business_name' => $name, 'is_active' => true]);
    }

    private function orderFor(Reseller $reseller, array $overrides = []): Order
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'gamevion'],
            ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
        );

        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'wallet_reseller_id' => $reseller->id,
            'order_number' => 'PG-'.uniqid(),
            'customer_email' => 'wallet@example.com',
            'player_id' => '123456',
            'supplier_id' => $supplier->id,
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 945,
            'transaction_fee' => 0,
            'final_amount' => 945,
            'platform_profit' => 45,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'payment_method' => 'wallet',
        ], $overrides));
    }

    public function test_index_lists_only_this_resellers_orders(): void
    {
        $mine = $this->reseller('Mine');
        $other = $this->reseller('Other');
        $this->orderFor($mine);
        $this->orderFor($other);

        $response = $this->withToken($this->tokenFor($mine))->getJson('/api/reseller-portal/orders');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_never_exposes_internal_financial_fields(): void
    {
        $reseller = $this->reseller();
        $this->orderFor($reseller);

        $response = $this->withToken($this->tokenFor($reseller))->getJson('/api/reseller-portal/orders');

        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('cost_price', $row);
        $this->assertArrayNotHasKey('platform_profit', $row);
        $this->assertArrayNotHasKey('affiliate_profit', $row);
    }

    public function test_show_returns_404_for_another_resellers_order(): void
    {
        $mine = $this->reseller('Mine');
        $other = $this->reseller('Other');
        $order = $this->orderFor($other);

        $this->withToken($this->tokenFor($mine))
            ->getJson("/api/reseller-portal/orders/{$order->order_number}")
            ->assertNotFound();
    }

    public function test_show_returns_the_order_detail_for_its_own_order(): void
    {
        $reseller = $this->reseller();
        $order = $this->orderFor($reseller);

        $this->withToken($this->tokenFor($reseller))
            ->getJson("/api/reseller-portal/orders/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('order_number', $order->order_number)
            ->assertJsonPath('delivery_status', 'delivered');
    }
}
