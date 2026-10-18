<?php

namespace Tests\Feature;

use App\Models\AffiliateUser;
use App\Models\Game;
use App\Models\Order;
use App\Services\Notification\OrderStatusCard;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Support\ContactMask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OrderCredentialFixtures;
use Tests\TestCase;

/**
 * ADR-125 decisions 31/38/39: anyone holding an order number (Track Order,
 * Reverb, the WhatsApp card) or the affiliate sees a login order's
 * identifier masked, even after its game loses the schema or is deleted.
 * A UID order is unchanged.
 */
class Adr125LoginLabelMaskingTest extends TestCase
{
    use OrderCredentialFixtures;
    use RefreshDatabase;

    private function loginOrder(array $overrides = []): Order
    {
        $game = Game::query()->create([
            'name' => 'Roblox', 'slug' => 'roblox', 'delivery_mode' => 'manual', 'delivery_subtext' => 'x',
            'validation_rules' => ['credential_fields' => [
                ['key' => 'email', 'label' => 'Roblox email', 'type' => 'email', 'required' => true, 'secret' => false, 'is_identifier' => true],
            ]],
        ]);
        $order = Order::factory()->forAffiliate($this->primaryAffiliate())->create([
            'game_id' => $game->id, 'player_id' => 'player@example.com', 'server_id' => null,
            'payment_status' => PaymentStatus::Paid, 'delivery_status' => DeliveryStatus::Delivered,
        ] + $overrides);
        $this->credentialFor($order);

        return $order;
    }

    public function test_identifier_masks_by_value(): void
    {
        $this->assertSame('p••••@example.com', ContactMask::identifier('player@example.com'));
        $this->assertSame('ga••••99', ContactMask::identifier('gamer1999'));
        $this->assertSame('•••', ContactMask::identifier('abc'));
        $this->assertNull(ContactMask::identifier(''));
    }

    public function test_track_order_masks_a_login_order_and_leaves_a_uid_order_alone(): void
    {
        $login = $this->loginOrder();
        $uid = Order::factory()->forAffiliate($this->primaryAffiliate())->create(['player_id' => '123456789', 'server_id' => '2001']);

        $this->getJson("/api/track-order/{$login->order_number}")->assertOk()
            ->assertJsonPath('player_id', 'p••••@example.com')
            ->assertJsonPath('server_id', null)
            ->assertJsonPath('fulfilment_kind', 'login')
            ->assertJsonPath('player_label', 'Roblox email')
            ->assertJsonPath('login_wipe_hours', 24);

        $this->getJson("/api/track-order/{$uid->order_number}")->assertOk()
            ->assertJsonPath('player_id', '123456789')
            ->assertJsonPath('server_id', '2001')
            ->assertJsonPath('fulfilment_kind', 'uid');
    }

    public function test_the_mask_holds_after_the_game_loses_its_schema_or_is_deleted(): void
    {
        $order = $this->loginOrder();
        $order->game->update(['validation_rules' => null]);

        $this->getJson("/api/track-order/{$order->order_number}")->assertJsonPath('player_id', 'p••••@example.com');

        $order->game->delete();
        $this->getJson("/api/track-order/{$order->order_number}")
            ->assertJsonPath('player_id', 'p••••@example.com')
            ->assertJsonPath('player_label', 'Login');
    }

    public function test_the_whatsapp_card_masks_the_login_and_asks_for_a_password_change(): void
    {
        $card = OrderStatusCard::render($this->loginOrder(), ['name' => 'PekanGame', 'host' => 'pekangame.space']);

        $this->assertStringContainsString('Delivered to login p••••@example.com', $card);
        $this->assertStringNotContainsString('player@example.com', $card);
        $this->assertStringContainsString('change your game password', $card);
        $this->assertStringNotContainsString('Player ID', $card);
    }

    public function test_the_affiliate_sees_the_login_masked(): void
    {
        $order = $this->loginOrder();
        $token = AffiliateUser::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $this->primaryAffiliate()->id,
            'name' => 'Owner', 'email' => 'owner@affiliate.test', 'password' => 'password', 'is_active' => true,
        ])->createToken('affiliate')->plainTextToken;

        $this->withToken($token)->getJson("/api/affiliate/orders/{$order->order_number}")->assertOk()
            ->assertJsonPath('player_id', 'p••••@example.com');
    }
}
