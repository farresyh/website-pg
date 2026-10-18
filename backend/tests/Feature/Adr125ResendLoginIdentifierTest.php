<?php

namespace Tests\Feature;

use App\Jobs\ResendOrderDeliveryJob;
use App\Models\AdminUser;
use App\Models\CredentialReveal;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderCredential;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderResendService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OrderCredentialFixtures;
use Tests\TestCase;

/**
 * ADR-125 decision 43: a resend may correct a login's identifier; the
 * order's player_id and the stored login's `fields` change together, and
 * the correction is audited.
 */
class Adr125ResendLoginIdentifierTest extends TestCase
{
    use OrderCredentialFixtures;
    use RefreshDatabase;

    private function failedLoginOrder(): Order
    {
        $supplier = Supplier::query()->create([
            'name' => 'Roblox A', 'slug' => 'roblox-a', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR',
        ]);
        $game = Game::query()->create([
            'name' => 'Roblox', 'slug' => 'roblox', 'delivery_mode' => 'manual', 'delivery_subtext' => 'x',
            'validation_rules' => ['credential_fields' => [
                ['key' => 'email', 'label' => 'Roblox email', 'type' => 'email', 'required' => true, 'secret' => false, 'is_identifier' => true],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'secret' => true, 'is_identifier' => false],
            ]],
        ]);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '800 Robux', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'MANUAL-1',
        ]);
        $order = Order::factory()->forAffiliate($this->primaryAffiliate())->create([
            'game_id' => $game->id, 'package_id' => $package->id, 'supplier_id' => $supplier->id, 'supplier_product_ref' => 'MANUAL-1',
            'player_id' => 'typo@example.com', 'server_id' => null, 'cost_price' => 900, 'standard_selling_price' => 1000,
            'selling_price' => 1000, 'platform_profit' => 100, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid, 'delivery_status' => DeliveryStatus::Failed,
        ]);
        $this->credentialFor($order, [
            'fields' => [['key' => 'email', 'label' => 'Roblox email', 'value' => 'typo@example.com']],
            'secret_fields' => [['key' => 'password', 'label' => 'Password']],
        ]);

        return $order;
    }

    public function test_the_correction_updates_player_id_and_the_stored_login_together(): void
    {
        $order = $this->failedLoginOrder();

        $result = app(OrderResendService::class)->resend($order, $order->package, null, 'Aina Admin', 'Right@Example.com');

        $this->assertSame('right@example.com', $result->player_id);
        $this->assertSame(DeliveryStatus::Pending, $result->delivery_status);
        $this->assertSame(
            [['key' => 'email', 'label' => 'Roblox email', 'value' => 'right@example.com']],
            OrderCredential::query()->where('order_id', $order->id)->sole()->fields,
        );
    }

    public function test_an_invalid_identifier_or_a_server_id_is_refused_and_nothing_changes(): void
    {
        $order = $this->failedLoginOrder();

        foreach ([['not-an-email', null], ['right@example.com', '2001']] as [$playerId, $serverId]) {
            try {
                app(OrderResendService::class)->resend($order, $order->package, null, 'Aina Admin', $playerId, $serverId);
                $this->fail('Expected a validation error');
            } catch (ValidationException) {
            }
        }

        $this->assertSame('typo@example.com', $order->fresh()->player_id);
        $this->assertSame('typo@example.com', OrderCredential::query()->where('order_id', $order->id)->sole()->fields[0]['value']);
    }

    public function test_the_admin_request_is_checked_and_audited(): void
    {
        Bus::fake([ResendOrderDeliveryJob::class]);
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $order = $this->failedLoginOrder();

        $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $order->package_id, 'player_id' => 'nope'])
            ->assertJsonValidationErrors('player_id');
        $this->assertSame(0, CredentialReveal::query()->count());

        $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $order->package_id, 'player_id' => 'right@example.com'])->assertOk();

        $audit = CredentialReveal::query()->sole();
        $this->assertSame([CredentialReveal::ACTION_IDENTIFIER, 'email', $admin->id], [$audit->action, $audit->field_key, $audit->actor_id]);
        Bus::assertDispatched(ResendOrderDeliveryJob::class);
    }
}
