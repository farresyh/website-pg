<?php

namespace Tests\Feature;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\Game;
use App\Models\JokiOrder;
use App\Models\JokiSetting;
use App\Models\JokiTier;
use App\Models\Order;
use App\Models\OrderCredential;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Joki\JokiCatalog;
use App\Services\Pricing\PricingBasis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CheckoutControllerFixtures;
use Tests\TestCase;

/**
 * ADR-126 PR 2b, decisions 1, 2, 7, 8, 12, 13: a joki order goes through
 * the one storefront checkout with a `joki` block. The price is
 * JokiPricingService's, on the Fixed basis at the panel's payout share,
 * and the order is written with its `joki_orders` snapshot in one go. The
 * `/joki` page reads the catalog and a quote through the same pricing.
 */
class Adr126JokiCheckoutTest extends TestCase
{
    use CheckoutControllerFixtures;
    use RefreshDatabase;

    /** What the admin's joki switch does (PR 4): set joki up, then switch it on. */
    private function enableJoki(): void
    {
        app(JokiCatalog::class)->ensure();
        JokiSetting::current()->update(['is_enabled' => true, 'terms_html' => '<p>No refunds after play starts.</p>']);
        $this->primaryAffiliate()->update(['joki_enabled' => true]);
    }

    private function game(): Game
    {
        return $this->calculator()->game;
    }

    private function calculator(): Package
    {
        return app(JokiCatalog::class)->ensure();
    }

    private function rank(string $tier, ?int $division, ?int $stars): array
    {
        return ['tier_id' => JokiTier::query()->where('name', $tier)->value('id'), 'division' => $division, 'stars' => $stars];
    }

    private function jokiPayload(array $joki = [], array $overrides = []): array
    {
        $payload = $this->payload($this->game(), $this->calculator(), $overrides + [
            'credentials' => ['login_type' => 'Moonton', 'user_id' => '123456789', 'login' => 'player_one', 'password' => 'hunter2'],
            'credential_consent' => true,
            'joki' => $joki + [
                'from' => $this->rank('Epic', 5, 0),
                'to' => $this->rank('Epic', 4, 0),
                'is_express' => false,
                'hero_request' => 'Ling, Fanny',
                'role' => 'Jungler',
                'play_window' => '8pm – 12am',
            ],
        ]);
        unset($payload['player_id']);

        return $payload;
    }

    public function test_a_joki_checkout_creates_a_fixed_order_with_its_snapshot(): void
    {
        $this->bindGateway();
        $this->enableJoki();

        $this->postJson('/api/checkout', $this->jokiPayload())->assertCreated();

        $order = Order::query()->sole();
        $this->assertSame(PricingBasis::Fixed, $order->pricing_basis);
        $this->assertSame([1000, 1000, 1000, 0, 0], [$order->selling_price, $order->standard_selling_price, $order->cost_price, $order->platform_profit, $order->affiliate_profit]);
        $this->assertSame(1000 + 210, $order->final_amount);
        $this->assertSame(Supplier::assignmentPool()->id, $order->supplier_id);
        $this->assertSame($this->calculator()->id, $order->package_id);
        $this->assertSame('player_one', $order->player_id);
        $this->assertNotNull(OrderCredential::query()->where('order_id', $order->id)->first());

        $joki = JokiOrder::query()->sole();
        $this->assertSame($order->id, $joki->order_id);
        $this->assertSame('Epic V 0★', $joki->from_rank['label']);
        $this->assertSame('Epic IV 0★', $joki->to_rank['label']);
        $this->assertSame([['label' => 'Epic', 'stars' => 5, 'rate_rm' => 2, 'amount_rm' => 10]], $joki->quote['lines']);
        $this->assertSame(10, $joki->quote['total_rm']);
        $this->assertNotEmpty($joki->quote['tiers']);
        $this->assertSame(100.0, $joki->payout_share_pct);
        $this->assertSame('<p>No refunds after play starts.</p>', $joki->terms_snapshot);
        $this->assertSame(['Ling, Fanny', 'Jungler', '8pm – 12am'], [$joki->hero_request, $joki->role, $joki->play_window]);
        $this->assertFalse($joki->is_express);
    }

    public function test_the_payout_share_at_checkout_is_the_one_kept(): void
    {
        $this->bindGateway();
        $this->enableJoki();
        JokiSetting::current()->update(['payout_share_pct' => 85]);

        $this->postJson('/api/checkout', $this->jokiPayload())->assertCreated();
        JokiSetting::current()->update(['payout_share_pct' => 50]);

        $order = Order::query()->sole();
        $this->assertSame([1000, 850, 150], [$order->selling_price, $order->cost_price, $order->platform_profit]);
        $this->assertSame(85.0, JokiOrder::query()->sole()->payout_share_pct);
    }

    public function test_joki_is_sold_only_with_both_switches_on_an_owned_brand(): void
    {
        $this->bindGateway();

        $this->postJson('/api/checkout', $this->jokiPayload())->assertJsonValidationErrors('package_id');

        JokiSetting::current()->update(['is_enabled' => true]);
        $this->postJson('/api/checkout', $this->jokiPayload())->assertJsonValidationErrors('package_id');

        $third = Affiliate::query()->create(['business_name' => 'Acme', 'email' => 'a@acme.test', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active', 'is_owned' => false, 'joki_enabled' => true]);
        AffiliateDomain::query()->create(['affiliate_id' => $third->id, 'hostname' => 'shop.acme.test', 'status' => 'active']);
        $this->postJson('/api/checkout', $this->jokiPayload(), ['X-Storefront-Host' => 'shop.acme.test'])->assertJsonValidationErrors('package_id');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_joki_block_belongs_to_the_joki_game_only(): void
    {
        $this->bindGateway();
        $this->enableJoki();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $this->postJson('/api/checkout', $this->payload($game, $package, ['joki' => ['from' => $this->rank('Epic', 5, 0)]]))->assertJsonValidationErrors('joki');

        $payload = $this->jokiPayload();
        unset($payload['joki']);
        $this->postJson('/api/checkout', $payload)->assertJsonValidationErrors('joki.from');

        $this->postJson('/api/checkout', $this->jokiPayload(['role' => 'Coach']))->assertJsonValidationErrors('joki.role');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_replay_must_buy_the_same_climb(): void
    {
        $this->bindGateway();
        $this->enableJoki();
        $key = (string) Str::uuid();

        $this->postJson('/api/checkout', $this->jokiPayload([], ['idempotency_key' => $key]))->assertCreated();
        $this->postJson('/api/checkout', $this->jokiPayload([], ['idempotency_key' => $key]))->assertOk();
        $this->postJson('/api/checkout', $this->jokiPayload(['to' => $this->rank('Epic', 3, 0)], ['idempotency_key' => $key]))
            ->assertStatus(422)->assertJsonPath('code', 'idempotency_mismatch');

        $this->assertSame(1, Order::query()->count());
    }

    public function test_the_review_total_is_the_charge(): void
    {
        $this->bindGateway();
        $this->enableJoki();

        $this->postJson('/api/checkout/preview-totals', [
            'game_id' => $this->game()->id,
            'package_id' => $this->calculator()->id,
            'channel_code' => 'FPX_ABMB',
            'joki' => ['from' => $this->rank('Epic', 5, 0), 'to' => $this->rank('Epic', 4, 0), 'is_express' => true],
        ])->assertOk()
            ->assertJsonPath('selling_price_sen', 1500)
            ->assertJsonPath('transaction_fee_sen', 210)
            ->assertJsonPath('final_amount_sen', 1710);
    }

    public function test_the_joki_page_reads_the_catalog_and_a_quote_only_where_joki_is_on(): void
    {
        $this->getJson('/api/joki/catalog')->assertForbidden();

        $this->enableJoki();
        $catalog = $this->getJson('/api/joki/catalog')->assertOk();
        $catalog->assertJsonPath('calculator_package_id', $this->calculator()->id)
            ->assertJsonPath('settings.min_stars', 5)
            ->assertJsonPath('settings.grading_price_rm', 39)
            ->assertJsonPath('settings.grading_lands_at_stars', 15)
            ->assertJsonPath('settings.roles.3', 'Jungler')
            ->assertJsonPath('tiers.4.name', 'Epic')
            ->assertJsonPath('tiers.4.rate_rm', 2);
        $this->assertSame('password', collect($catalog->json('credential_fields'))->firstWhere('key', 'password')['type']);
        $this->assertArrayNotHasKey('payout_share_pct', $catalog->json('settings'));

        $this->postJson('/api/joki/quote', [
            'package_id' => $this->calculator()->id,
            'joki' => ['from' => $this->rank('Legend', 1, 2), 'to' => $this->rank('Mythic', null, 20), 'is_express' => false],
        ])->assertOk()->assertJsonPath('total_rm', 65)->assertJsonPath('lines.1.amount_rm', 39);
    }

    public function test_joki_is_set_up_once_and_nothing_exists_before_it(): void
    {
        $this->assertSame(0, Game::query()->joki()->count());
        JokiSetting::current()->update(['is_enabled' => true]);
        $this->primaryAffiliate()->update(['joki_enabled' => true]);
        $this->getJson('/api/joki/catalog')->assertNotFound();

        $first = app(JokiCatalog::class)->ensure();
        $second = app(JokiCatalog::class)->ensure();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Game::query()->joki()->count());
        $this->assertSame(1, Package::query()->where('game_id', $first->game_id)->count());
        $this->assertSame(Supplier::assignmentPool()->id, $first->supplier_id);
        $this->assertTrue($first->game->isLoginGame());
        $this->assertSame(30, $first->game->credential_max_days);
        $this->getJson('/api/joki/catalog')->assertOk();
    }
}
