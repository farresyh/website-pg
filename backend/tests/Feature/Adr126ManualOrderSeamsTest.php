<?php

namespace Tests\Feature;

use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\AdminUser;
use App\Models\CustomerNotification;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderCredential;
use App\Models\PlatformSettings;
use App\Models\Supplier;
use App\Models\SupplierLoginLink;
use App\Models\WhatsAppGroup;
use App\Services\Fulfillment\ManualDeliveryService;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use App\Services\Supplier\Manual\SupplierGroupNotifier;
use App\Services\Supplier\Manual\SupplierLoginLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OrderCredentialFixtures;
use Tests\Support\OrderFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-126 2026-10-23 addendum, decisions 17/18/20/22: the manual-order
 * seams Joki needs, built for every manual order (Login Top-up too).
 * Reassigning an order's supplier is simulated with a plain update until
 * Joki's Assign exists (PR 2).
 */
class Adr126ManualOrderSeamsTest extends TestCase
{
    use OrderCredentialFixtures;
    use OrderFulfillmentFixtures;
    use RefreshDatabase;

    private Supplier $a;

    private Supplier $b;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openwa.base_url' => 'http://openwa.test',
            'services.openwa.session_id' => 'bot-session',
            'services.openwa.api_key' => 'bot-key',
        ]);
        $this->a = $this->manualSupplier('a');
        $this->b = $this->manualSupplier('b');
    }

    private function manualSupplier(string $slug): Supplier
    {
        $supplier = Supplier::query()->create([
            'name' => "Penjoki {$slug}", 'slug' => "penjoki-{$slug}", 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR',
        ]);
        WhatsAppGroup::query()->create(['owner_type' => 'supplier', 'owner_id' => $supplier->id, 'whatsapp_group_id' => "{$slug}@g.us"]);

        return $supplier;
    }

    private function pendingOn(Supplier $supplier, array $overrides = []): Order
    {
        $order = $this->paidOrder(['supplier_id' => $supplier->id, 'server_id' => null, 'supplier_product_ref' => 'MANUAL-1'] + $overrides);
        $this->credentialFor($order);

        return app(OrderFulfillmentService::class)->fulfill($order);
    }

    private function reassign(Order $order, Supplier $to): void
    {
        Order::query()->whereKey($order->id)->update(['supplier_id' => $to->id]);
        app(SupplierGroupNotifier::class)->orderPosted($order->id);
    }

    private function postsFor(Order $order)
    {
        return CustomerNotification::query()->where('order_id', $order->id)->where('event', SupplierGroupNotifier::EVENT_ORDER_POST)->orderBy('id')->get();
    }

    private function send(CustomerNotification $notification): void
    {
        Http::fake(['openwa.test/*' => Http::response(['ok' => true])]);
        (new SendCustomerWhatsAppJob($notification->id))->handle();
    }

    // --- Decision 17: the post key carries the supplier ---------------------

    public function test_a_reassigned_order_is_posted_to_the_new_group_and_the_old_post_is_dropped(): void
    {
        Queue::fake();
        $order = $this->pendingOn($this->a);
        $this->reassign($order, $this->b);

        [$old, $new] = $this->postsFor($order)->all();
        $this->assertSame('a@g.us', $old->chat_id);
        $this->assertSame('b@g.us', $new->chat_id);
        $this->assertStringEndsWith(":supplier:{$this->b->id}", $new->dedupe_key);
        // Same reference: a new supplier's first look, not a retry.
        $this->assertStringContainsString('ORDER BARU', $new->message);

        $this->send($old->fresh());
        $this->assertSame(CustomerNotification::STATUS_SKIPPED, $old->fresh()->status);
    }

    public function test_a_retry_with_a_new_reference_is_still_labelled_retry(): void
    {
        Queue::fake();
        $order = $this->pendingOn($this->a);
        Order::query()->whereKey($order->id)->update(['reference_number' => 'REF-NEWATTEMPT']);
        app(SupplierGroupNotifier::class)->orderPosted($order->id);

        $this->assertStringContainsString('ORDER RETRY', $this->postsFor($order)->last()->message);
    }

    public function test_the_dashboard_counts_a_reassigned_order_whose_new_post_never_went_out(): void
    {
        Queue::fake();
        $order = $this->pendingOn($this->a);
        $notifier = app(SupplierGroupNotifier::class);
        $this->assertSame(0, $notifier->unpostedWaitingCount());

        WhatsAppGroup::query()->where('owner_id', $this->b->id)->update(['is_active' => false]);
        $this->reassign($order, $this->b);

        $this->assertSame(1, $notifier->unpostedWaitingCount());
    }

    // --- Decision 18: Delivered/Failed re-check the supplier under the lock --

    public function test_a_stale_done_from_the_previous_supplier_is_refused_and_moves_no_money(): void
    {
        Queue::fake();
        $order = $this->pendingOn($this->a);
        $seenByOldSupplier = Order::query()->with('supplier')->find($order->id);
        Order::query()->whereKey($order->id)->update(['supplier_id' => $this->b->id]);

        foreach (['confirmDelivered', 'confirmFailed'] as $method) {
            try {
                $method === 'confirmDelivered'
                    ? app(ManualDeliveryService::class)->confirmDelivered($seenByOldSupplier, null, null, 'wa:old', $this->a->id, 'supplier_bot')
                    : app(ManualDeliveryService::class)->confirmFailed($seenByOldSupplier, 'tak dapat', 'wa:old', $this->a->id, 'supplier_bot');
                $this->fail("{$method} accepted a supplier that no longer owns the order.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('order', $e->errors());
            }
        }

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
        $this->assertDatabaseCount('supplier_ledger_entries', 0);
    }

    public function test_the_current_supplier_still_confirms(): void
    {
        Queue::fake();
        $order = $this->pendingOn($this->a);
        Order::query()->whereKey($order->id)->update(['supplier_id' => $this->b->id]);

        $done = app(ManualDeliveryService::class)->confirmDelivered($order->fresh(), null, null, 'wa:new', $this->b->id, 'supplier_bot');

        $this->assertSame(DeliveryStatus::Delivered, $done->delivery_status);
    }

    // --- Decision 20: one explicit stale clock -------------------------------

    public function test_the_stale_clock_starts_when_the_post_reaches_the_group_not_on_any_touch(): void
    {
        Queue::fake();
        $this->a->update(['api_config' => ['reply_sla_minutes' => 30]]);
        $order = $this->pendingOn($this->a);
        $this->assertNull($order->fresh()->manual_activity_at);

        $this->travel(20)->minutes();
        $this->send($this->postsFor($order)->sole());
        $this->assertNotNull($order->fresh()->manual_activity_at);

        // 35 minutes after entering Pending but 15 after the post: not stale.
        $this->travel(15)->minutes();
        $this->assertFalse($order->fresh()->isStalePending());

        // An unrelated touch no longer resets the clock.
        $this->travel(16)->minutes();
        Order::query()->whereKey($order->id)->update(['customer_email' => 'changed@example.com']);
        $this->assertTrue($order->fresh()->isStalePending());
    }

    public function test_without_a_post_the_clock_falls_back_to_updated_at(): void
    {
        Queue::fake();
        $this->a->update(['api_config' => ['reply_sla_minutes' => 30]]);
        $order = $this->pendingOn($this->a);

        $this->travel(31)->minutes();
        $this->assertTrue($order->fresh()->isStalePending());
    }

    public function test_the_manual_sla_may_be_up_to_three_days(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        $this->patchJson("/api/partner-suppliers/{$this->a->id}/sla", ['reply_sla_minutes' => 4320])->assertOk();
        $this->patchJson("/api/partner-suppliers/{$this->a->id}/sla", ['reply_sla_minutes' => 4321])->assertJsonValidationErrors('reply_sla_minutes');
    }

    // --- Decision 22: each login's wipe ceiling is on its row ---------------

    private function loginGame(?int $maxDays = null): Game
    {
        return Game::query()->create([
            'name' => 'Joki MLBB', 'slug' => 'joki-mlbb-'.uniqid(), 'is_active' => true,
            'delivery_mode' => 'manual', 'delivery_subtext' => 'Siap dalam 1–3 hari',
            'validation_rules' => ['credential_fields' => [['key' => 'password', 'label' => 'Password', 'secret' => true]]],
            'credential_max_days' => $maxDays,
        ]);
    }

    public function test_the_ceiling_is_the_games_own_days_else_the_policy_d(): void
    {
        $plain = $this->credentialFor($this->paidOrder(['order_number' => 'PG-PLAIN']));
        $joki = $this->credentialFor($this->paidOrder(['order_number' => 'PG-JOKI', 'game_id' => $this->loginGame(30)->id]));

        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $plain->fresh()->secrets_expire_at->timestamp, 2);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $joki->fresh()->secrets_expire_at->timestamp, 2);

        $this->travel(15)->days();
        $this->artisan('app:wipe-order-credentials')->assertSuccessful();
        $this->assertNotNull($plain->fresh()->purged_at);
        $this->assertNull($joki->fresh()->purged_at);

        $this->travel(16)->days();
        $this->artisan('app:wipe-order-credentials')->assertSuccessful();
        $this->assertNotNull($joki->fresh()->purged_at);
    }

    public function test_changing_d_or_a_games_days_moves_the_unwiped_logins_it_governs(): void
    {
        // Both logins are set "at the same time": credentialFor() reads now(),
        // so unfrozen the second one could land a second later and the exact
        // comparisons below flaked on CI.
        $this->freezeTime();
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());
        $game = $this->loginGame();
        $plain = $this->credentialFor($this->paidOrder(['order_number' => 'PG-PLAIN']));
        $onGame = $this->credentialFor($this->paidOrder(['order_number' => 'PG-GAME', 'game_id' => $game->id]));
        $setAt = $plain->fresh()->secrets_set_at;

        $this->putJson('/api/settings/customer-login-policy', [
            'credential_close_grace_hours' => 24, 'credential_unpaid_hours' => 24, 'credential_max_days' => 7,
            'credential_reveal_limit_per_hour' => 10, 'credential_consent_text' => PlatformSettings::DEFAULT_CREDENTIAL_CONSENT_TEXT,
        ])->assertOk();
        $this->assertEquals($setAt->copy()->addDays(7), $plain->fresh()->secrets_expire_at);
        $this->assertEquals($setAt->copy()->addDays(7), $onGame->fresh()->secrets_expire_at);

        $this->putJson("/api/games/{$game->id}", [
            'name' => $game->name, 'slug' => $game->slug, 'is_active' => true,
            'delivery_mode' => 'manual', 'delivery_subtext' => 'Siap dalam 1–3 hari', 'credential_max_days' => 30,
        ])->assertOk()->assertJsonPath('credential_max_days', 30);
        $this->assertEquals($setAt->copy()->addDays(30), $onGame->fresh()->secrets_expire_at);
        $this->assertEquals($setAt->copy()->addDays(7), $plain->fresh()->secrets_expire_at);

        $this->putJson("/api/games/{$game->id}", [
            'name' => $game->name, 'slug' => $game->slug, 'is_active' => true,
            'delivery_mode' => 'manual', 'delivery_subtext' => 'Siap dalam 1–3 hari', 'credential_max_days' => 91,
        ])->assertJsonValidationErrors('credential_max_days');
    }

    public function test_the_supplier_link_expires_with_the_login(): void
    {
        Queue::fake();
        $order = $this->pendingOn($this->a, ['game_id' => $this->loginGame(30)->id]);
        $credential = OrderCredential::query()->where('order_id', $order->id)->sole();

        app(SupplierLoginLinkService::class)->mint($order->fresh());

        $this->assertEquals($credential->secrets_expire_at, SupplierLoginLink::query()->sole()->expires_at);
    }
}
