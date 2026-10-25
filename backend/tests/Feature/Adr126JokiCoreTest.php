<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\AffiliateGame;
use App\Models\CustomerNotification;
use App\Models\Game;
use App\Models\JokiAssignment;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Dashboard\DashboardService;
use App\Services\Fulfillment\ManualDeliveryService;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Fulfillment\OrderResendService;
use App\Services\Joki\JokiAssignmentService;
use App\Services\Order\DeliveryStatus;
use App\Services\Pricing\OrderPricingResolver;
use App\Services\Pricing\PricingBasis;
use App\Services\Supplier\Manual\ManualSupplierStatusService;
use App\Services\Supplier\Manual\SupplierGroupNotifier;
use App\Services\Supplier\Manual\SupplierLoginLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\JokiFixtures;
use Tests\Support\OrderCredentialFixtures;
use Tests\Support\OrderFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-126 PR 2a (2026-10-23 addendum, decisions 2, 6, 7, 9, 10, 11, 15, 16,
 * 19 and F1): the Fixed basis, the assignment pool, Assign, and every
 * path that could change a joki order's agreed payout.
 */
class Adr126JokiCoreTest extends TestCase
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

    private function assign(Order $order, Supplier $to): Order
    {
        return app(JokiAssignmentService::class)->assign($order, $to->id, 'Aina Admin');
    }

    private function posts(Order $order)
    {
        return CustomerNotification::query()->where('order_id', $order->id)->where('event', SupplierGroupNotifier::EVENT_ORDER_POST)->orderBy('id')->get();
    }

    private function assertRefused(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail("Expected a refusal on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));
        }
    }

    // --- Decisions 2, 7, 11: the Fixed basis ---------------------------------

    public function test_fixed_pricing_takes_the_payout_from_the_share_and_keeps_the_sen(): void
    {
        $resolver = app(OrderPricingResolver::class);

        $full = $resolver->resolveFixed(7000, 100.0);
        $this->assertSame([7000, 7000, 0, 0], [$full->sellingPriceSen, $full->costPriceSen, $full->platformProfitSen, $full->affiliateProfitSen]);
        $this->assertSame(PricingBasis::Fixed, $full->basis);

        $share = $resolver->resolveFixed(7000, 85.0);
        $this->assertSame([5950, 1050], [$share->costPriceSen, $share->platformProfitSen]);

        // One third of RM7: rounded to the sen, the platform keeps the remainder.
        $third = $resolver->resolveFixed(700, 33.33);
        $this->assertSame(700, $third->costPriceSen + $third->platformProfitSen);
        $this->assertSame(233, $third->costPriceSen);

        foreach ([[7000, 0.0], [7000, 100.01], [0, 100.0]] as [$selling, $pct]) {
            try {
                $resolver->resolveFixed($selling, $pct);
                $this->fail("resolveFixed accepted {$selling} at {$pct}%.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_every_pricing_basis_has_a_label(): void
    {
        foreach (PricingBasis::cases() as $basis) {
            $this->assertNotSame('', $basis->label());
        }
        $this->assertSame('Fixed (joki)', PricingBasis::Fixed->label());
    }

    // --- Decision 2: the joki game is in no catalog --------------------------

    public function test_the_joki_game_is_hidden_from_every_brands_catalog(): void
    {
        $this->primaryAffiliate();
        $this->jokiPackage();
        Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        $brand = Affiliate::query()->create(['business_name' => 'Acme', 'email' => 'a@acme.test', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active']);
        AffiliateDomain::query()->create(['affiliate_id' => $brand->id, 'hostname' => 'shop.acme.test', 'status' => 'active']);

        foreach ([[], ['X-Storefront-Host' => 'shop.acme.test']] as $headers) {
            $slugs = collect($this->getJson('/api/catalog/games', $headers)->assertOk()->json())->pluck('slug');
            $this->assertContains('mobile-legends', $slugs);
            $this->assertNotContains('joki-mlbb', $slugs);
            $this->getJson('/api/catalog/games/joki-mlbb', $headers)->assertNotFound();
        }

        $this->assertContains($this->jokiGame()->id, AffiliateGame::hiddenGameIdsFor($brand->id));
        $this->assertContains($this->jokiGame()->id, AffiliateGame::hiddenGameIdsFor($this->primaryAffiliate()->id));
    }

    // --- Decision 15: the pool waits silently -------------------------------

    public function test_a_pool_order_posts_nowhere_and_is_not_counted_as_a_manual_wait(): void
    {
        $order = $this->pendingJokiOrder();

        $this->assertSame(DeliveryStatus::Pending, $order->delivery_status);
        $this->assertCount(0, CustomerNotification::query()->where('order_id', $order->id)->get());
        $this->assertSame(0, app(SupplierGroupNotifier::class)->unpostedWaitingCount());

        $this->travel(2)->days();
        $this->artisan('app:reconcile-pending-deliveries')->assertSuccessful();
        $this->assertSame(0, CustomerNotification::query()->where('event', SupplierGroupNotifier::EVENT_SLA_REMINDER)->count());

        $this->assertNull(app(SupplierLoginLinkService::class)->mint($order->fresh()));

        $alerts = collect(app(DashboardService::class)->health()['attention']['items'])->pluck('key');
        $this->assertNotContains('manual_supplier_without_group', $alerts);
        $this->assertNotContains('supplier_posts_not_delivered', $alerts);
    }

    public function test_a_pool_order_can_fail_but_never_be_delivered(): void
    {
        $order = $this->pendingJokiOrder();
        $pool = Supplier::assignmentPool();

        $this->assertRefused(fn () => app(ManualDeliveryService::class)->confirmDelivered($order, null, null, 'Aina', $pool->id), 'order');

        $failed = app(ManualDeliveryService::class)->confirmFailed($order, 'Tiada penjoki', 'Aina', $pool->id);
        $this->assertSame(DeliveryStatus::Failed, $failed->delivery_status);
        $this->assertRefused(fn () => app(ManualDeliveryService::class)->correctFailedToDelivered($failed, null, 'oops', 'Aina'), 'order');
    }

    public function test_the_pool_is_on_no_supplier_screen(): void
    {
        $amir = $this->penjoki('amir');
        $ordinary = Supplier::query()->create(['name' => 'Roblox A', 'slug' => 'roblox-a', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR', 'is_active' => true]);
        $pool = Supplier::assignmentPool();
        $ids = fn ($rows) => collect($rows)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$amir->id, $ordinary->id], $ids(app(DashboardService::class)->health()['suppliers']));

        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        foreach (['/api/middleware/suppliers', '/api/partner-suppliers'] as $url) {
            $listed = $ids($this->getJson($url)->assertOk()->json());
            $this->assertContains($amir->id, $listed, $url);
            $this->assertNotContains($pool->id, $listed, $url);
        }
        // A penjoki doesn't supply ordinary packages either.
        $this->assertSame([$ordinary->id], $ids($this->getJson('/api/manual-suppliers')->assertOk()->json()));
    }

    public function test_the_pool_is_switched_by_the_joki_switch_only(): void
    {
        $this->assertRefused(fn () => app(ManualSupplierStatusService::class)->setActive(Supplier::assignmentPool(), false, AdminUser::factory()->create()->id), 'is_active');
        $this->assertTrue(Supplier::assignmentPool()->fresh()->is_active);
    }

    // --- Decision 16: Assign ---------------------------------------------------

    public function test_assign_moves_a_waiting_order_to_a_penjoki_posts_it_and_audits_it(): void
    {
        $order = $this->pendingJokiOrder();
        $amir = $this->penjoki('amir');

        $assigned = $this->assign($order, $amir);

        $this->assertSame($amir->id, $assigned->supplier_id);
        $this->assertSame(7000, $assigned->cost_price);
        $this->assertNotNull($assigned->manual_activity_at);
        $post = $this->posts($order)->sole();
        $this->assertSame('amir@g.us', $post->chat_id);
        $this->assertStringContainsString('ORDER BARU', $post->message);

        $audit = JokiAssignment::query()->sole();
        $this->assertSame([$order->id, Supplier::assignmentPool()->id, $amir->id, 'Aina Admin'], [$audit->order_id, $audit->from_supplier_id, $audit->to_supplier_id, $audit->assigned_by]);
    }

    public function test_reassigning_kills_the_old_link_and_the_old_penjoki_can_no_longer_answer(): void
    {
        $order = $this->pendingJokiOrder();
        $amir = $this->penjoki('amir');
        $bala = $this->penjoki('bala');
        $this->assign($order, $amir);
        $oldLink = app(SupplierLoginLinkService::class)->mint($order->fresh());
        $token = basename($oldLink);

        $this->assign($order->fresh(), $bala);

        $this->assertNull(app(SupplierLoginLinkService::class)->find($token));
        $this->assertSame('bala@g.us', $this->posts($order)->last()->chat_id);
        $this->assertRefused(fn () => app(ManualDeliveryService::class)->confirmDelivered($order->fresh(), null, null, 'wa:amir', $amir->id, 'supplier_bot'), 'order');
        $this->assertCount(2, JokiAssignment::query()->get());
    }

    public function test_moving_an_order_back_to_an_earlier_penjoki_posts_again_and_keeps_old_links_dead(): void
    {
        $order = $this->pendingJokiOrder();
        $amir = $this->penjoki('amir');
        $bala = $this->penjoki('bala');
        $this->assign($order, $amir);
        $firstLink = basename(app(SupplierLoginLinkService::class)->mint($order->fresh()));

        $this->assign($order->fresh(), $bala);
        $this->assign($order->fresh(), $amir);

        $this->assertSame(['amir@g.us', 'bala@g.us', 'amir@g.us'], $this->posts($order)->pluck('chat_id')->all());
        $this->assertNull(app(SupplierLoginLinkService::class)->find($firstLink));
        $freshLink = basename(app(SupplierLoginLinkService::class)->mint($order->fresh()));
        $this->assertNotNull(app(SupplierLoginLinkService::class)->find($freshLink));
    }

    public function test_assign_refuses_anything_but_a_waiting_joki_order_to_a_ready_penjoki(): void
    {
        $order = $this->pendingJokiOrder();
        $ordinary = Supplier::query()->create(['name' => 'Roblox A', 'slug' => 'roblox-a', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR']);

        $this->assertRefused(fn () => $this->assign($order, $ordinary), 'supplier_id');
        $this->assertRefused(fn () => $this->assign($order, $this->penjoki('nogroup', withGroup: false)), 'supplier_id');
        $this->assertRefused(fn () => $this->assign($order, $this->penjoki('off', active: false)), 'supplier_id');
        $this->assertRefused(fn () => $this->assign($order, Supplier::assignmentPool()), 'supplier_id');

        $amir = $this->penjoki('amir');
        $this->assign($order, $amir);
        $this->assertRefused(fn () => $this->assign($order->fresh(), $amir), 'supplier_id');

        $delivered = app(ManualDeliveryService::class)->confirmDelivered($order->fresh(), null, null, 'wa:amir', $amir->id, 'supplier_bot');
        $this->assertRefused(fn () => $this->assign($delivered, $this->penjoki('late')), 'order');

        $topUp = $this->paidOrder(['order_number' => 'PG-NOTJOKI', 'supplier_id' => $ordinary->id, 'supplier_product_ref' => 'MANUAL-1', 'server_id' => null]);
        $this->credentialFor($topUp);
        $topUp = app(OrderFulfillmentService::class)->fulfill($topUp);
        $this->assertRefused(fn () => $this->assign($topUp, $amir), 'order');
    }

    public function test_assign_over_http_is_for_admins(): void
    {
        $order = $this->pendingJokiOrder();
        $amir = $this->penjoki('amir');

        $admin = AdminUser::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/orders/{$order->id}/joki/assign", [])->assertJsonValidationErrors('supplier_id');
        $this->postJson("/api/orders/{$order->id}/joki/assign", ['supplier_id' => $amir->id])
            ->assertOk()->assertJsonPath('supplier_id', $amir->id);
        $this->assertSame($admin->name, JokiAssignment::query()->sole()->assigned_by);
    }

    // --- Decision 19: a penjoki with work can't be switched off ---------------

    public function test_a_penjoki_with_a_waiting_order_cannot_be_switched_off_until_reassigned(): void
    {
        $order = $this->pendingJokiOrder();
        $amir = $this->penjoki('amir');
        $bala = $this->penjoki('bala');
        $this->assign($order, $amir);
        $adminId = AdminUser::factory()->create()->id;

        $this->assertRefused(fn () => app(ManualSupplierStatusService::class)->setActive($amir, false, $adminId), 'is_active');

        $this->assign($order->fresh(), $bala);
        app(ManualSupplierStatusService::class)->setActive($amir->fresh(), false, $adminId);
        $this->assertFalse($amir->fresh()->is_active);
    }

    // --- Decisions 6, 9, 10, F1: the payout is never changed by a path ------

    public function test_retry_after_a_failed_joki_returns_it_to_the_pool_at_the_same_payout(): void
    {
        $order = $this->pendingJokiOrder();
        $amir = $this->penjoki('amir');
        $this->assign($order, $amir);
        app(ManualDeliveryService::class)->confirmFailed($order->fresh(), 'Akaun dikunci', 'wa:amir', $amir->id, 'supplier_bot');

        $retried = app(OrderFulfillmentService::class)->fulfill($order->fresh());

        $this->assertSame(DeliveryStatus::Pending, $retried->delivery_status);
        $this->assertSame(Supplier::assignmentPool()->id, $retried->supplier_id);
        $this->assertSame(7000, $retried->cost_price);
    }

    public function test_resend_keeps_the_payout_and_never_swaps_a_joki_package(): void
    {
        $order = $this->pendingJokiOrder();
        $amir = $this->penjoki('amir');
        $this->assign($order, $amir);
        $failed = app(ManualDeliveryService::class)->confirmFailed($order->fresh(), 'Akaun dikunci', 'wa:amir', $amir->id, 'supplier_bot');
        $route = $this->jokiPackage('Epic → Mythic');
        $route->update(['cost_price' => 1, 'standard_selling_price' => 1]);

        $impact = app(OrderResendService::class)->preflight($failed, $route);
        $this->assertSame('package_id', $impact->blockedField);

        $same = app(OrderResendService::class)->preflight($failed, $failed->package);
        $this->assertNull($same->blockedField);
        $this->assertSame(7000, $same->costPriceSen);
        $this->assertSame(0, $same->platformProfitSen);

        $resent = app(OrderResendService::class)->resend($failed, $failed->package, null, 'Aina');
        $this->assertSame(7000, $resent->cost_price);
        $this->assertSame(Supplier::assignmentPool()->id, $resent->supplier_id);
    }

    public function test_a_joki_delivery_is_always_at_the_agreed_payout(): void
    {
        $order = $this->pendingJokiOrder();
        $amir = $this->penjoki('amir');
        $this->assign($order, $amir);

        $this->assertRefused(fn () => app(ManualDeliveryService::class)->confirmDelivered($order->fresh(), 8000, 'Extra hours', 'Aina', $amir->id), 'real_cost_sen');

        $done = app(ManualDeliveryService::class)->confirmDelivered($order->fresh(), null, null, 'wa:amir', $amir->id, 'supplier_bot');
        $this->assertSame(7000, $done->real_cost_price_sen);
    }

    public function test_joki_packages_are_outside_bulk_markup_and_the_package_screens(): void
    {
        $joki = $this->jokiPackage();
        $joki->update(['markup_percent' => 0]);
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        $this->postJson('/api/settings/platform/bulk-markup', ['markup_percent' => 20])->assertOk();
        $this->assertSame(0.0, (float) $joki->fresh()->markup_percent);

        $this->patchJson("/api/packages/{$joki->id}/markup", ['markup_percent' => 20])->assertUnprocessable()->assertJsonValidationErrors('package');
        $this->patchJson("/api/packages/{$joki->id}/status", ['is_active' => false])->assertUnprocessable();
        $this->deleteJson("/api/packages/{$joki->id}")->assertUnprocessable();
        $this->postJson("/api/games/{$joki->game_id}/packages/manual", ['name' => 'x', 'supplier_id' => Supplier::assignmentPool()->id, 'cost_price' => 100])->assertUnprocessable();
        $this->assertTrue(Package::query()->whereKey($joki->id)->exists());
    }
}
