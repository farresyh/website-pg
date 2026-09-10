<?php

namespace Tests\Feature\Http\Controllers\ResellerPortal;

use App\Models\AffiliateUser;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerWebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-084 PR-3 decision 4/10: full self-service delivery-webhook
 * management from the Reseller (wallet) portal.
 */
class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(): Reseller
    {
        return Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true]);
    }

    private function tokenFor(Reseller $reseller): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $reseller->id,
            'name' => 'Staff', 'email' => 'staff+'.$reseller->id.'@wallet-reseller.test',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    public function test_show_returns_null_when_no_endpoint_is_configured(): void
    {
        $token = $this->tokenFor($this->reseller());

        $this->withToken($token)->getJson('/api/reseller-portal/webhook')
            ->assertOk()
            ->assertJsonPath('webhook', null);
    }

    public function test_store_creates_the_endpoint_and_returns_the_secret_once(): void
    {
        $token = $this->tokenFor($this->reseller());

        $response = $this->withToken($token)->postJson('/api/reseller-portal/webhook', [
            'url' => 'https://example.test/hook',
        ]);

        $response->assertCreated()
            ->assertJsonPath('webhook.url', 'https://example.test/hook')
            ->assertJsonPath('webhook.is_active', true);
        $this->assertStringStartsWith('pgwh_', $response->json('secret'));

        // A subsequent URL update does not re-reveal the secret.
        $this->withToken($token)->postJson('/api/reseller-portal/webhook', ['url' => 'https://example.test/hook2'])
            ->assertOk()
            ->assertJsonPath('secret', null)
            ->assertJsonPath('webhook.url', 'https://example.test/hook2');
    }

    public function test_store_rejects_a_non_https_url(): void
    {
        $token = $this->tokenFor($this->reseller());

        $this->withToken($token)->postJson('/api/reseller-portal/webhook', ['url' => 'http://example.test/hook'])
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/reseller-portal/webhook', ['url' => 'not-a-url'])
            ->assertUnprocessable();
    }

    public function test_rotate_secret_returns_a_new_one(): void
    {
        $reseller = $this->reseller();
        $token = $this->tokenFor($reseller);
        $original = $this->withToken($token)->postJson('/api/reseller-portal/webhook', ['url' => 'https://example.test/hook'])->json('secret');

        $rotated = $this->withToken($token)->postJson('/api/reseller-portal/webhook/rotate-secret')->json('secret');

        $this->assertNotSame($original, $rotated);
        $this->assertStringStartsWith('pgwh_', $rotated);
    }

    public function test_rotate_secret_404s_without_an_endpoint(): void
    {
        $token = $this->tokenFor($this->reseller());

        $this->withToken($token)->postJson('/api/reseller-portal/webhook/rotate-secret')->assertNotFound();
    }

    public function test_status_toggle_pauses_the_endpoint(): void
    {
        $reseller = $this->reseller();
        $token = $this->tokenFor($reseller);
        $this->withToken($token)->postJson('/api/reseller-portal/webhook', ['url' => 'https://example.test/hook']);

        $this->withToken($token)->patchJson('/api/reseller-portal/webhook/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('webhook.is_active', false);
        $this->assertFalse($reseller->webhook->fresh()->is_active);
    }

    public function test_destroy_removes_the_endpoint(): void
    {
        $reseller = $this->reseller();
        $token = $this->tokenFor($reseller);
        $this->withToken($token)->postJson('/api/reseller-portal/webhook', ['url' => 'https://example.test/hook']);

        $this->withToken($token)->deleteJson('/api/reseller-portal/webhook')->assertNoContent();
        $this->assertNull($reseller->fresh()->webhook);
    }

    public function test_deliveries_lists_only_this_resellers_own_rows(): void
    {
        $mine = $this->reseller();
        $other = Reseller::query()->create(['business_name' => 'Other', 'is_active' => true]);
        $token = $this->tokenFor($mine);

        foreach ([$mine, $other] as $r) {
            $order = Order::query()->create([
                'affiliate_id' => $this->primaryAffiliate()->id,
                'wallet_reseller_id' => $r->id,
                'order_number' => 'PG-'.uniqid(),
                'customer_email' => 'buyer@example.test',
                'player_id' => '1', 'cost_price' => 900, 'standard_selling_price' => 900, 'selling_price' => 1000,
                'transaction_fee' => 0, 'final_amount' => 1000, 'platform_profit' => 100, 'affiliate_profit' => 0,
                'payment_status' => 'paid', 'delivery_status' => 'delivered',
            ]);
            ResellerWebhookDelivery::query()->create([
                'reseller_id' => $r->id, 'order_id' => $order->id,
                'event' => 'order.delivered', 'event_id' => 'e-'.$r->id, 'payload' => [], 'status' => 'delivered',
            ]);
        }

        $response = $this->withToken($token)->getJson('/api/reseller-portal/webhook/deliveries');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('order.delivered', $response->json('data.0.event'));
    }

    public function test_an_affiliate_account_type_cannot_reach_these_routes(): void
    {
        // account.type:reseller gate — an Affiliate user is rejected.
        $affiliate = $this->primaryAffiliate();
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $affiliate->id,
            'name' => 'Aff', 'email' => 'aff@x.test', 'password' => Hash::make('x'), 'is_active' => true,
        ]);

        $this->withToken($user->createToken('affiliate')->plainTextToken)
            ->getJson('/api/reseller-portal/webhook')
            ->assertForbidden();
    }
}
