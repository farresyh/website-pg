<?php

namespace Tests\Feature\Http\Controllers;

use App\Jobs\FulfillOrderJob;
use App\Models\BlacklistEntry;
use App\Models\Game;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\OrderCredential;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Models\Supplier;
use App\Services\Fraud\BlacklistEntryType;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Pricing\MembershipPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\CheckoutControllerFixtures;
use Tests\TestCase;

/**
 * ADR-125 decisions 32/37/40/41 + ADR-124 decision 8: a login game's
 * checkout sets player_id from the identifier, stores the login with the
 * order in one write, keeps the password out of the idempotency hash and
 * honours the per-game member-pricing switch.
 */
class CheckoutControllerLoginGameTest extends TestCase
{
    use CheckoutControllerFixtures;
    use RefreshDatabase;

    /** @return array{game: Game, package: Package} */
    private function loginGameAndPackage(array $gameOverrides = []): array
    {
        $supplier = Supplier::query()->create([
            'name' => 'Roblox A', 'slug' => 'roblox-a', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(array_merge([
            'name' => 'Roblox', 'slug' => 'roblox', 'is_active' => true, 'delivery_mode' => 'manual', 'delivery_subtext' => 'Within 30 minutes',
            'validation_rules' => ['credential_fields' => [
                ['key' => 'email', 'label' => 'Roblox email', 'type' => 'email', 'required' => true, 'secret' => false, 'is_identifier' => true],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'secret' => true, 'is_identifier' => false],
                ['key' => 'login_via', 'label' => 'Login via', 'type' => 'select', 'options' => ['Email', 'Google'], 'required' => false, 'secret' => false, 'is_identifier' => false],
            ]],
        ], $gameOverrides));
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '800 Robux', 'cost_price' => 1000, 'standard_selling_price' => 1200, 'markup_percent' => 20,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'MANUAL-1',
        ]);

        return ['game' => $game, 'package' => $package];
    }

    private function loginPayload(Game $game, Package $package, array $overrides = []): array
    {
        $payload = $this->payload($game, $package, $overrides + [
            'credentials' => ['email' => '  Player@Example.COM ', 'password' => '  pass word  ', 'login_via' => 'Email'],
            'credential_consent' => true,
        ]);
        if (! array_key_exists('player_id', $overrides)) {
            unset($payload['player_id']);
        }

        return $payload;
    }

    public function test_the_order_takes_its_player_id_from_the_identifier_and_stores_the_login(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->loginGameAndPackage();

        $this->postJson('/api/checkout', $this->loginPayload($game, $package))->assertCreated();

        $order = Order::query()->sole();
        $this->assertSame('player@example.com', $order->player_id);
        $this->assertNull($order->server_id);

        $credential = OrderCredential::query()->sole();
        $this->assertSame($order->id, $credential->order_id);
        $this->assertSame([
            ['key' => 'email', 'label' => 'Roblox email', 'value' => 'player@example.com'],
            ['key' => 'login_via', 'label' => 'Login via', 'value' => 'Email'],
        ], $credential->fields);
        $this->assertSame([['key' => 'password', 'label' => 'Password']], $credential->secret_fields);
        // Sent exactly as typed: TrimStrings skips credentials.*.
        $this->assertSame(['password' => '  pass word  '], $credential->secrets);
        $this->assertSame(PlatformSettings::current()->credential_consent_text, $credential->consent_text);
    }

    public function test_the_password_is_never_stored_in_plain_text_or_in_the_hash(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->loginGameAndPackage();
        $payload = $this->loginPayload($game, $package);

        $this->postJson('/api/checkout', $payload)->assertCreated();

        $raw = DB::table('order_credentials')->value('secrets');
        $this->assertStringNotContainsString('pass word', $raw);

        // Same key, same non-secret fields, new password: a replay, not a mismatch.
        $payload['credentials']['password'] = 'something else';
        $this->postJson('/api/checkout', $payload)->assertOk();
        $this->assertSame(1, Order::query()->count());

        // A different identifier under the same key is a different request.
        $payload['credentials']['email'] = 'other@example.com';
        $this->postJson('/api/checkout', $payload)->assertStatus(422)->assertJsonPath('code', 'idempotency_mismatch');
    }

    public function test_a_client_player_id_server_id_or_missing_consent_is_refused(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->loginGameAndPackage();

        $this->postJson('/api/checkout', $this->loginPayload($game, $package, ['player_id' => '123']))->assertJsonValidationErrors('player_id');
        $this->postJson('/api/checkout', $this->loginPayload($game, $package, ['server_id' => '1']))->assertJsonValidationErrors('player_id');
        $this->postJson('/api/checkout', $this->loginPayload($game, $package, ['credential_consent' => false]))->assertJsonValidationErrors('credential_consent');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_the_login_form_is_checked_against_the_schema(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->loginGameAndPackage();
        $creds = ['email' => 'a@b.co', 'password' => 'x', 'login_via' => 'Email'];

        $cases = [
            'credentials.email' => [...$creds, 'email' => 'not-an-email'],
            'credentials.password' => [...$creds, 'password' => null],
            'credentials.login_via' => [...$creds, 'login_via' => 'Facebook'],
            'credentials' => [...$creds, 'pin' => '1234'],
        ];

        foreach ($cases as $field => $credentials) {
            $this->postJson('/api/checkout', $this->loginPayload($game, $package, ['credentials' => $credentials]))
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_uid_game_refuses_credentials(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package, ['credentials' => ['email' => 'a@b.co']]))
            ->assertJsonValidationErrors('credentials');
    }

    public function test_the_blacklist_sees_the_identifier_whatever_its_case(): void
    {
        $this->bindGateway();
        BlacklistEntry::query()->create([
            'type' => BlacklistEntryType::PlayerId->value, 'value' => 'player@example.com', 'reason' => 'Chargeback', 'is_active' => true,
        ]);
        ['game' => $game, 'package' => $package] = $this->loginGameAndPackage();

        $this->postJson('/api/checkout', $this->loginPayload($game, $package))->assertJsonValidationErrors('player_id');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderCredential::query()->count());
    }

    public function test_a_full_voucher_login_order_is_stored_with_its_login_before_fulfilment(): void
    {
        Bus::fake([FulfillOrderJob::class]);
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->loginGameAndPackage();
        $this->voucher(['amount' => 5000, 'remaining' => 5000]);

        $this->postJson('/api/checkout', $this->loginPayload($game, $package, ['voucher_code' => 'KRS-CHECKOUT-TEST']))->assertCreated();

        $order = Order::query()->sole();
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertTrue(OrderCredential::query()->where('order_id', $order->id)->exists());
        Bus::assertDispatched(FulfillOrderJob::class);
    }

    public function test_member_pricing_off_gives_the_standard_price_in_the_preview_and_at_checkout(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->loginGameAndPackage(['member_pricing_enabled' => false]);
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id, 'email' => 'member@example.com',
            'membership_plan_id' => MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail()->id,
            'status' => 'active', 'cycle_started_at' => now(), 'quota_remaining_sen' => 2000, 'expires_at' => now()->addDays(20),
        ]);
        $headers = ['Authorization' => 'Bearer '.app(MembershipSessionTokenService::class)->issue($this->primaryAffiliate()->id, 'member@example.com')];

        $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $game->id, 'package_id' => $package->id, 'channel_code' => 'FPX_ABMB',
        ], $headers)->assertOk()->assertJsonPath('selling_price_sen', 1200);

        $this->postJson('/api/checkout', $this->loginPayload($game, $package, ['customer_email' => 'typed@example.com']), $headers)->assertCreated();

        $order = Order::query()->sole();
        $this->assertSame(1200, $order->selling_price);
        $this->assertNull($order->membership_id);
        // Decision 35: identity is still bound to the member (ADR-068 decision 16).
        $this->assertSame('member@example.com', $order->customer_email);
        $this->assertSame(2000, $membership->fresh()->quota_remaining_sen);

        $catalog = $this->getJson("/api/catalog/games/{$game->slug}/packages", $headers)->assertOk()->json();
        $this->assertArrayNotHasKey('member_price_sen', $catalog[0]);
    }

    public function test_a_game_with_member_pricing_off_is_never_the_savings_badge_sample(): void
    {
        $this->loginGameAndPackage(['member_pricing_enabled' => false]);

        $this->assertNull(app(MembershipPricingService::class)->representativePackage());
    }

    public function test_the_public_game_detail_carries_the_form_and_consent_text_only_for_a_login_game(): void
    {
        ['game' => $game] = $this->loginGameAndPackage();
        ['game' => $uid] = $this->gameAndPackage();

        $this->getJson("/api/catalog/games/{$game->slug}")->assertOk()
            ->assertJsonPath('credential_fields.0.key', 'email')
            ->assertJsonPath('credential_consent_text', PlatformSettings::current()->credential_consent_text);
        $this->getJson("/api/catalog/games/{$uid->slug}")->assertOk()
            ->assertJsonPath('credential_fields', [])
            ->assertJsonPath('credential_consent_text', null);
    }
}
