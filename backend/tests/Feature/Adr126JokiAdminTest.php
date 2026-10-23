<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\JokiRoute;
use App\Models\JokiSetting;
use App\Models\JokiTier;
use App\Models\Order;
use App\Models\Supplier;
use App\Services\Dashboard\DashboardService;
use App\Services\Fulfillment\ManualDeliveryService;
use App\Services\Joki\JokiAssignmentService;
use App\Services\Joki\JokiCatalog;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\JokiFixtures;
use Tests\Support\OrderCredentialFixtures;
use Tests\Support\OrderFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-126 PR 4a (2026-10-23 addendum, decisions 23-25 and F4): the Joki
 * board behind the admin page and Dashboard card, joki out of the general
 * Need Action / stuck counts and the Manual Queue, the `/orders` type
 * filter, penjoki, and the panel settings (whole ringgit only).
 */
class Adr126JokiAdminTest extends TestCase
{
    use JokiFixtures;
    use OrderCredentialFixtures;
    use OrderFulfillmentFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'services.openwa.base_url' => 'http://openwa.test',
            'services.openwa.session_id' => 'bot-session',
            'services.openwa.api_key' => 'bot-key',
        ]);
    }

    private function actAs(string $role = 'super_admin'): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => $role]));
    }

    private function failedJoki(): Order
    {
        $order = $this->pendingJokiOrder();

        return app(ManualDeliveryService::class)->confirmFailed($order, 'Tiada penjoki', 'Aina', Supplier::assignmentPool()->id);
    }

    /** @return list<string> */
    private function numbers(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('order_number')->all();
    }

    // --- F4: joki leaves the general queues, never to nowhere ---------------

    public function test_a_failed_joki_leaves_need_action_and_shows_on_the_joki_card(): void
    {
        $this->actAs();
        $joki = $this->failedJoki();
        $topup = $this->paidOrder(['order_number' => 'PG-TOPUP1', 'delivery_status' => DeliveryStatus::Failed->value]);

        $this->assertSame(['PG-TOPUP1'], $this->numbers('/api/orders?status=need_action'));
        $this->assertSame(1, $this->getJson('/api/orders/summary')->json('need_action'));

        $health = app(DashboardService::class)->health();
        $this->assertSame(1, collect($health['attention']['items'])->firstWhere('key', 'needs_action_orders')['count']);
        $this->assertSame(1, $health['joki']['needs_voucher']);
        $this->assertSame([$joki->order_number], $this->numbers('/api/joki-admin/orders?filter=needs_voucher'));

        // `/orders` keeps every order (decision 25): the Failed tab still has both.
        $this->assertEqualsCanonicalizing([$joki->order_number, 'PG-TOPUP1'], $this->numbers('/api/orders?status=failed'));
    }

    public function test_a_stale_joki_is_on_the_joki_card_not_the_stuck_count_or_manual_queue(): void
    {
        $this->actAs('admin');
        $waiting = $this->pendingJokiOrder();
        $this->travel(4)->days();

        $health = app(DashboardService::class)->health();
        $this->assertSame(0, $health['stuck_orders']['value']);
        $this->assertSame(['waiting' => 1, 'in_progress' => 0, 'stale' => 1, 'needs_voucher' => 0], $health['joki']);

        $this->assertSame([], $this->getJson('/api/manual-queue')->assertOk()->json());
        $this->assertSame([$waiting->order_number], $this->numbers('/api/joki-admin/orders?filter=stale'));
    }

    // --- Decision 23: the board's filters ------------------------------------

    public function test_the_board_splits_waiting_in_progress_done_and_failed(): void
    {
        $this->actAs('admin');
        $amir = $this->penjoki('amir');
        $waiting = $this->pendingJokiOrder();
        $playing = app(JokiAssignmentService::class)->assign($this->pendingJokiOrder(), $amir->id, 'Aina');
        $done = app(JokiAssignmentService::class)->assign($this->pendingJokiOrder(), $amir->id, 'Aina');
        app(ManualDeliveryService::class)->confirmDelivered($done, null, null, 'Aina', $amir->id);
        $failed = $this->failedJoki();
        $this->paidOrder(['order_number' => 'PG-TOPUP1', 'delivery_status' => DeliveryStatus::Delivered->value]);

        $this->assertSame([$waiting->order_number], $this->numbers('/api/joki-admin/orders?filter=waiting'));
        $this->assertSame([$playing->order_number], $this->numbers('/api/joki-admin/orders?filter=in_progress'));
        $this->assertSame([$done->order_number], $this->numbers('/api/joki-admin/orders?filter=done'));
        $this->assertSame([$failed->order_number], $this->numbers('/api/joki-admin/orders?filter=failed'));
        $this->assertCount(4, $this->numbers('/api/joki-admin/orders'));
        $this->assertSame(['waiting' => 1, 'in_progress' => 1, 'stale' => 0, 'needs_voucher' => 1, 'all' => 4], $this->getJson('/api/joki-admin/summary')->json());

        $row = collect($this->getJson('/api/joki-admin/orders?filter=in_progress')->json('data'))->first();
        $this->assertSame('Penjoki amir', $row['penjoki']);
        $this->assertSame(7000, $row['payout_sen']);
        $this->assertArrayHasKey('sla_due_at', $row);
        $this->assertArrayHasKey('from_rank', $row);
    }

    // --- Decision 25: the `/orders` type filter ------------------------------

    public function test_orders_filter_by_type(): void
    {
        $this->actAs('admin');
        $joki = $this->pendingJokiOrder();
        $login = $this->paidOrder(['order_number' => 'PG-LOGIN1']);
        $this->credentialFor($login);
        $this->paidOrder(['order_number' => 'PG-TOPUP1']);

        $this->assertSame([$joki->order_number], $this->numbers('/api/orders?type=joki'));
        $this->assertSame(['PG-LOGIN1'], $this->numbers('/api/orders?type=login'));
        $this->assertSame(['PG-TOPUP1'], $this->numbers('/api/orders?type=topup'));
        $this->assertCount(3, $this->numbers('/api/orders?type=all'));
    }

    // --- Decision 23: penjoki --------------------------------------------------

    public function test_penjoki_list_shows_open_orders_and_what_is_owed(): void
    {
        $this->actAs('admin');
        $amir = $this->penjoki('amir');
        $this->penjoki('siti', withGroup: false, active: false);
        $done = app(JokiAssignmentService::class)->assign($this->pendingJokiOrder(), $amir->id, 'Aina');
        app(ManualDeliveryService::class)->confirmDelivered($done, null, null, 'Aina', $amir->id);
        app(JokiAssignmentService::class)->assign($this->pendingJokiOrder(), $amir->id, 'Aina');

        $rows = collect($this->getJson('/api/joki-admin/penjoki')->assertOk()->json())->keyBy('name');
        $this->assertSame(['Penjoki amir', 'Penjoki siti'], $rows->keys()->sort()->values()->all());
        $this->assertSame(1, $rows['Penjoki amir']['open_orders']);
        $this->assertSame(7000, $rows['Penjoki amir']['owed_sen']);
        $this->assertTrue($rows['Penjoki amir']['has_group']);
        $this->assertFalse($rows['Penjoki siti']['has_group']);
    }

    public function test_a_penjoki_is_created_as_a_manual_supplier_with_the_role(): void
    {
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());

        $this->postJson('/api/middleware/suppliers', [
            'name' => 'Penjoki Hafiz', 'driver' => 'manual', 'slug' => 'penjoki-hafiz', 'currency' => 'MYR',
            'funding_mode' => 'pay_after', 'joki_role' => 'penjoki',
        ])->assertCreated();
        $this->assertTrue(Supplier::query()->where('slug', 'penjoki-hafiz')->firstOrFail()->isPenjoki());

        // Only a pay-after manual supplier can be a penjoki, and nobody makes a second pool.
        $this->postJson('/api/middleware/suppliers', [
            'name' => 'X', 'driver' => 'manual', 'slug' => 'x', 'currency' => 'MYR', 'funding_mode' => 'prepaid', 'joki_role' => 'penjoki',
        ])->assertJsonValidationErrors('joki_role');
        $this->postJson('/api/middleware/suppliers', [
            'name' => 'Y', 'driver' => 'manual', 'slug' => 'y', 'currency' => 'MYR', 'funding_mode' => 'pay_after', 'joki_role' => 'pool',
        ])->assertJsonValidationErrors('joki_role');
    }

    // --- Decisions 1, 4, 7: settings in whole ringgit -------------------------

    public function test_settings_are_super_admin_and_whole_ringgit(): void
    {
        $this->actAs('admin');
        $this->getJson('/api/joki-admin/settings')->assertForbidden();

        $this->actAs();
        $this->getJson('/api/joki-admin/settings')->assertOk()->assertJsonPath('settings.is_enabled', false);

        $this->putJson('/api/joki-admin/settings', ['grading_price_rm' => 39.5])->assertJsonValidationErrors(['grading_price_rm' => 'Whole ringgit only, no sen.']);
        $this->putJson('/api/joki-admin/settings', ['payout_share_pct' => 101])->assertJsonValidationErrors('payout_share_pct');
        $this->putJson('/api/joki-admin/settings', ['grading_price_rm' => 45, 'grading_express_price_rm' => 40])
            ->assertJsonValidationErrors('grading_express_price_rm');

        $this->putJson('/api/joki-admin/settings', ['payout_share_pct' => 85, 'grading_express_price_rm' => 49])->assertOk();
        $this->assertSame([85.0, 49], [JokiSetting::current()->payout_share_pct, JokiSetting::current()->grading_express_price_rm]);
    }

    public function test_switching_joki_on_sets_up_the_game_and_calculator(): void
    {
        $this->actAs();
        $this->assertNull(app(JokiCatalog::class)->calculator());

        $this->putJson('/api/joki-admin/settings', ['is_enabled' => true])->assertOk();

        $this->assertTrue(JokiSetting::current()->is_enabled);
        $this->assertNotNull(app(JokiCatalog::class)->calculator());
    }

    public function test_tier_rates_are_whole_ringgit_and_express_is_not_below_reguler(): void
    {
        $this->actAs();
        $epic = JokiTier::query()->where('name', 'Epic')->firstOrFail();

        $this->putJson('/api/joki-admin/tiers', ['tiers' => [['id' => $epic->id, 'rate_rm' => 3, 'express_rate_rm' => 2]]])
            ->assertJsonValidationErrors('tiers.0.express_rate_rm');
        $this->putJson('/api/joki-admin/tiers', ['tiers' => [['id' => $epic->id, 'rate_rm' => 2.5, 'express_rate_rm' => 4]]])
            ->assertJsonValidationErrors('tiers.0.rate_rm');

        $this->putJson('/api/joki-admin/tiers', ['tiers' => [['id' => $epic->id, 'rate_rm' => 3, 'express_rate_rm' => 4, 'icon_url' => 'https://cdn.test/epic.png']]])->assertOk();
        $this->assertSame([3, 4, 'https://cdn.test/epic.png'], [$epic->fresh()->rate_rm, $epic->fresh()->express_rate_rm, $epic->fresh()->icon_url]);
    }

    // --- Decision 5: route packages ------------------------------------------

    public function test_a_route_package_is_a_joki_package_on_the_pool_with_a_valid_climb(): void
    {
        $this->actAs();
        $this->jokiGame();
        $epic = JokiTier::query()->where('name', 'Epic')->firstOrFail();
        $mythic = JokiTier::query()->where('name', 'Mythic')->firstOrFail();

        $this->postJson('/api/joki-admin/routes', [
            'name' => 'Epic V → Epic I', 'from' => ['tier_id' => $mythic->id, 'stars' => 0], 'to' => ['tier_id' => $epic->id, 'division' => 1, 'stars' => 0], 'price_rm' => 30,
        ])->assertJsonValidationErrors('to');

        $id = $this->postJson('/api/joki-admin/routes', [
            'name' => 'Epic V → Mythic', 'from' => ['tier_id' => $epic->id, 'division' => 5, 'stars' => 0], 'to' => ['tier_id' => $mythic->id, 'stars' => 15], 'price_rm' => 99,
        ])->assertCreated()->json('id');

        $route = JokiRoute::query()->with('package.game')->findOrFail($id);
        $this->assertTrue($route->package->game->is_joki);
        $this->assertSame(Supplier::assignmentPool()->id, $route->package->supplier_id);
        $this->assertSame(99, $route->price_rm);

        $this->putJson("/api/joki-admin/routes/{$id}", ['is_active' => false, 'price_rm' => 89])->assertOk();
        $this->assertFalse($route->package->fresh()->is_active);
        $this->assertSame(89, $route->fresh()->price_rm);
    }

    public function test_a_rank_model_change_that_breaks_a_route_or_the_bands_is_refused(): void
    {
        $this->actAs();
        $epic = JokiTier::query()->where('name', 'Epic')->firstOrFail();
        $mythic = JokiTier::query()->where('name', 'Mythic')->firstOrFail();
        $honor = JokiTier::query()->where('name', 'Mythical Honor')->firstOrFail();
        $this->postJson('/api/joki-admin/routes', [
            'name' => 'Epic V → Mythic', 'from' => ['tier_id' => $epic->id, 'division' => 5, 'stars' => 4], 'to' => ['tier_id' => $mythic->id, 'stars' => 15], 'is_quotation' => true,
        ])->assertCreated();

        // Epic V 4★ no longer exists if Epic has 4 stars a division.
        $this->putJson('/api/joki-admin/tiers', ['tiers' => [['id' => $epic->id, 'stars_per_division' => 4, 'rate_rm' => 2, 'express_rate_rm' => 3]]])
            ->assertJsonValidationErrors('tiers');
        $this->assertSame(5, $epic->fresh()->stars_per_division);

        $this->putJson('/api/joki-admin/tiers', ['tiers' => [['id' => $honor->id, 'mythic_from_stars' => 0, 'rate_rm' => 5, 'express_rate_rm' => 6]]])
            ->assertJsonValidationErrors('tiers');
        $this->assertSame(25, $honor->fresh()->mythic_from_stars);
    }

    public function test_order_detail_carries_the_joki_card_and_a_top_up_does_not(): void
    {
        $this->actAs('admin');
        $amir = $this->penjoki('amir');
        $order = app(JokiAssignmentService::class)->assign($this->pendingJokiOrder(), $amir->id, 'Aina');

        $joki = $this->getJson("/api/orders/{$order->id}")->assertOk()->json('joki');
        $this->assertTrue($joki['is_with_penjoki']);
        $this->assertSame([['from' => 'Joki – waiting for a penjoki', 'to' => 'Penjoki amir', 'by' => 'Aina']], collect($joki['assignments'])->map(fn ($a) => array_diff_key($a, ['at' => 1]))->all());
        $this->assertCount(10, $joki['tiers']);

        $topup = $this->paidOrder(['order_number' => 'PG-TOPUP1']);
        $this->assertNull($this->getJson("/api/orders/{$topup->id}")->assertOk()->json('joki'));
    }

    public function test_the_board_filters_by_search_penjoki_and_date_like_orders(): void
    {
        $this->actAs('admin');
        $amir = $this->penjoki('amir');
        $mine = app(JokiAssignmentService::class)->assign($this->pendingJokiOrder(['customer_email' => 'siti@example.com']), $amir->id, 'Aina');
        $other = $this->pendingJokiOrder();
        $other->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->assertSame([$mine->order_number], $this->numbers('/api/joki-admin/orders?search=siti@'));
        $this->assertSame([$mine->order_number], $this->numbers("/api/joki-admin/orders?search={$mine->order_number}"));
        $this->assertSame([$mine->order_number], $this->numbers("/api/joki-admin/orders?supplier_id={$amir->id}"));
        $today = now()->setTimezone('Asia/Kuala_Lumpur')->toDateString();
        $this->assertSame([$mine->order_number], $this->numbers("/api/joki-admin/orders?date_from={$today}&date_to={$today}"));
        $this->getJson('/api/joki-admin/orders?date_from=24-10-2026')->assertJsonValidationErrors('date_from');
    }
}
