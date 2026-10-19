<?php

namespace Tests\Feature;

use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\AdminUser;
use App\Models\CredentialReveal;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\OrderCredential;
use App\Models\Supplier;
use App\Models\SupplierLoginLink;
use App\Models\WhatsAppGroup;
use App\Services\Dashboard\DashboardService;
use App\Services\Fulfillment\ManualDeliveryService;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use App\Services\Supplier\Manual\SupplierGroupNotifier;
use App\Services\Supplier\Manual\SupplierLoginLinkService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OrderCredentialFixtures;
use Tests\Support\OrderFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-125 decisions 13/15/48-54/59: a manual supplier's Pending order is
 * posted to its WhatsApp group once per attempt through the persisted bot
 * queue, the login link is minted only at send time and only opens while
 * that attempt is waiting, and a late attempt gets one SLA reminder.
 */
class Adr125SupplierGroupPostTest extends TestCase
{
    use OrderCredentialFixtures;
    use OrderFulfillmentFixtures;
    use RefreshDatabase;

    private const GROUP = 'supplier-a@g.us';

    private Supplier $manual;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openwa.base_url' => 'http://openwa.test',
            'services.openwa.session_id' => 'bot-session',
            'services.openwa.api_key' => 'bot-key',
            'services.openwa.notification_gap_min_seconds' => 10,
            'services.openwa.notification_gap_max_seconds' => 30,
        ]);
        $this->manual = Supplier::query()->create([
            'name' => 'Roblox A', 'slug' => 'roblox-a', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR',
        ]);
        WhatsAppGroup::query()->create(['owner_type' => 'supplier', 'owner_id' => $this->manual->id, 'whatsapp_group_id' => self::GROUP]);
    }

    private function pendingLoginOrder(array $overrides = []): Order
    {
        $order = $this->paidOrder(['supplier_id' => $this->manual->id, 'server_id' => null, 'supplier_product_ref' => 'MANUAL-1'] + $overrides);
        $this->credentialFor($order);

        return app(OrderFulfillmentService::class)->fulfill($order);
    }

    private function posts(Order $order): Collection
    {
        return CustomerNotification::query()->where('order_id', $order->id)->where('event', SupplierGroupNotifier::EVENT_ORDER_POST)->orderBy('id')->get();
    }

    /** Runs the queued send and returns the text OpenWA received. */
    private function send(CustomerNotification $notification): ?string
    {
        $sent = null;
        Http::fake(['openwa.test/*' => function (Request $request) use (&$sent) {
            $sent = $request;

            return Http::response(['ok' => true]);
        }]);

        (new SendCustomerWhatsAppJob($notification->id))->handle();

        if ($sent === null) {
            return null;
        }
        $this->assertStringContainsString('/api/sessions/bot-session/', $sent->url());
        $this->assertSame(self::GROUP, $sent['chatId']);

        return $sent['text'];
    }

    private function tokenFrom(string $text): string
    {
        $this->assertMatchesRegularExpression('#/s/([A-Za-z0-9]{12})\b#', $text);
        preg_match('#/s/([A-Za-z0-9]{12})\b#', $text, $m);

        return $m[1];
    }

    public function test_a_pending_manual_order_is_posted_once_to_the_suppliers_group(): void
    {
        Queue::fake();
        $order = $this->pendingLoginOrder();

        $post = $this->posts($order)->sole();
        $this->assertSame(CustomerNotification::SESSION_BOT, $post->session);
        $this->assertSame(self::GROUP, $post->chat_id);
        $this->assertNull($post->phone);
        $this->assertSame(CustomerNotification::STATUS_QUEUED, $post->status);
        $this->assertStringContainsString('ORDER BARU', $post->message);
        $this->assertStringContainsString('Username: player_one', $post->message);
        // Decision 52: never a working link, nor the secret, in the stored row.
        $this->assertStringContainsString(SupplierLoginLinkService::PLACEHOLDER, $post->message);
        $this->assertStringNotContainsString('hunter2-secret', $post->message);
        Queue::assertPushed(SendCustomerWhatsAppJob::class, 1);

        // The same attempt never posts twice.
        app(SupplierGroupNotifier::class)->orderPosted($order->id);
        $this->assertCount(1, $this->posts($order));
    }

    public function test_the_customer_switch_never_stops_a_supplier_post(): void
    {
        Queue::fake();
        config(['services.openwa.cs_session_id' => null]);

        $order = $this->pendingLoginOrder();

        $this->assertSame(CustomerNotification::STATUS_QUEUED, $this->posts($order)->sole()->status);
    }

    public function test_a_supplier_with_no_active_group_gets_a_skipped_row(): void
    {
        Queue::fake();
        WhatsAppGroup::query()->update(['is_active' => false]);

        $order = $this->pendingLoginOrder();

        $post = $this->posts($order)->sole();
        $this->assertSame(CustomerNotification::STATUS_SKIPPED, $post->status);
        $this->assertSame('This supplier has no active WhatsApp group', $post->error);
        Queue::assertNotPushed(SendCustomerWhatsAppJob::class);
    }

    public function test_linking_the_group_later_posts_the_waiting_order_as_new(): void
    {
        Queue::fake();
        WhatsAppGroup::query()->delete();
        $order = $this->pendingLoginOrder();
        $this->assertSame(CustomerNotification::STATUS_SKIPPED, $this->posts($order)->sole()->status);

        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        $this->postJson("/api/partner-suppliers/{$this->manual->id}/whatsapp-group", ['whatsapp_group_id' => self::GROUP])->assertCreated();

        $post = $this->posts($order)->sole();
        $this->assertSame(CustomerNotification::STATUS_QUEUED, $post->status);
        $this->assertSame(self::GROUP, $post->chat_id);
        $this->assertStringContainsString('ORDER BARU', $post->message);
        Queue::assertPushed(SendCustomerWhatsAppJob::class, 1);
    }

    public function test_a_sandbox_or_api_supplier_order_is_never_posted(): void
    {
        Queue::fake();
        $sandbox = $this->paidOrder(['order_number' => 'KRS-TEST-SB', 'supplier_id' => $this->manual->id, 'is_test' => true, 'delivery_status' => DeliveryStatus::Pending->value]);
        $api = $this->paidOrder(['order_number' => 'KRS-TEST-API', 'delivery_status' => DeliveryStatus::Pending->value]);

        app(SupplierGroupNotifier::class)->orderPosted($sandbox->id);
        app(SupplierGroupNotifier::class)->orderPosted($api->id);

        $this->assertSame(0, CustomerNotification::query()->count());
    }

    public function test_the_link_is_minted_at_send_time_and_shows_the_login_only_on_post(): void
    {
        Queue::fake();
        $order = $this->pendingLoginOrder();

        $text = $this->send($this->posts($order)->sole());
        $token = $this->tokenFrom($text);

        $this->assertSame(CustomerNotification::STATUS_SENT, $this->posts($order)->sole()->status);
        $link = SupplierLoginLink::query()->sole();
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertSame($order->reference_number, $link->reference_number);

        $page = $this->get("/s/{$token}")->assertOk();
        $page->assertSee($order->order_number)->assertSee('player_one')->assertSee('Show login')->assertDontSee('hunter2-secret');
        $this->assertSame('no-store, private', $page->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $page->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('noindex', $page->headers->get('X-Robots-Tag'));

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/s/{$token}")->assertOk()->assertSee('hunter2-secret');

        $reveal = CredentialReveal::query()->sole();
        $this->assertSame(CredentialReveal::ACTOR_SUPPLIER, $reveal->actor_type);
        $this->assertSame($this->manual->id, $reveal->actor_id);
        $this->assertSame('password', $reveal->field_key);
    }

    public function test_the_link_dies_when_the_attempt_finishes_or_the_login_is_wiped(): void
    {
        Queue::fake();
        $order = $this->pendingLoginOrder();
        $token = $this->tokenFrom($this->send($this->posts($order)->sole()));

        OrderCredential::query()->update(['purged_at' => now()]);
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/s/{$token}")->assertStatus(410)->assertDontSee('hunter2-secret');
        OrderCredential::query()->update(['purged_at' => null]);

        app(ManualDeliveryService::class)->confirmDelivered($order->fresh(), null, null, 'Aina Admin');

        $this->get("/s/{$token}")->assertNotFound()->assertDontSee($order->order_number);
        $this->get('/s/AAAAAAAAAAAA')->assertNotFound();
        $this->assertSame(0, CredentialReveal::query()->count());
    }

    public function test_a_post_for_an_attempt_already_finished_is_skipped_at_send(): void
    {
        Queue::fake();
        $order = $this->pendingLoginOrder();
        app(ManualDeliveryService::class)->confirmDelivered($order->fresh(), null, null, 'Aina Admin');

        $this->assertNull($this->send($this->posts($order)->sole()));

        $this->assertSame(CustomerNotification::STATUS_SKIPPED, $this->posts($order)->sole()->status);
        $this->assertSame(0, SupplierLoginLink::query()->count());
    }

    /** Decision 48: a retry from Failed is a new reference, so a new post, labelled RETRY. */
    public function test_a_retry_after_gagal_posts_again_as_a_retry_and_kills_the_old_link(): void
    {
        Queue::fake();
        $order = $this->pendingLoginOrder();
        $oldToken = $this->tokenFrom($this->send($this->posts($order)->sole()));

        app(ManualDeliveryService::class)->confirmFailed($order->fresh(), 'Password salah', 'wa:60123456789', 'supplier_bot');
        $retried = app(OrderFulfillmentService::class)->fulfill($order->fresh());

        $this->assertSame(DeliveryStatus::Pending, $retried->delivery_status);
        $this->assertNotSame($order->reference_number, $retried->reference_number);
        $posts = $this->posts($order);
        $this->assertCount(2, $posts);
        $this->assertStringContainsString('ORDER RETRY', $posts[1]->message);

        $this->get("/s/{$oldToken}")->assertNotFound();
        $newToken = $this->tokenFrom($this->send($posts[1]));
        $this->get("/s/{$newToken}")->assertOk();
    }

    public function test_the_dashboard_counts_unposted_waiting_orders_and_suppliers_with_no_group(): void
    {
        Queue::fake();
        $attention = fn () => collect(app(DashboardService::class)->health()['attention']['items'])->pluck('count', 'key');

        $posted = $this->pendingLoginOrder();
        $this->assertNull($attention()->get('supplier_posts_not_delivered'));

        WhatsAppGroup::query()->update(['is_active' => false]);
        $this->pendingLoginOrder(['order_number' => 'KRS-TEST-2']);
        $this->assertSame(1, $attention()->get('supplier_posts_not_delivered'));
        $this->assertSame(1, $attention()->get('manual_supplier_without_group'));

        // A retry whose own post was skipped counts, though its first attempt was sent.
        $posts = $this->posts($posted);
        $posts->first()->update(['status' => CustomerNotification::STATUS_SENT]);
        app(ManualDeliveryService::class)->confirmFailed($posted->fresh(), 'Password salah', 'wa:60123456789', 'supplier_bot');
        app(OrderFulfillmentService::class)->fulfill($posted->fresh());
        $this->assertSame(2, $attention()->get('supplier_posts_not_delivered'));
    }

    public function test_one_sla_reminder_per_attempt(): void
    {
        Queue::fake();
        $this->manual->update(['api_config' => ['reply_sla_minutes' => 30]]);
        $order = $this->pendingLoginOrder();

        $this->artisan('app:reconcile-pending-deliveries')->assertSuccessful();
        $this->assertSame(0, CustomerNotification::query()->where('event', SupplierGroupNotifier::EVENT_SLA_REMINDER)->count());

        $this->travel(31)->minutes();
        $this->artisan('app:reconcile-pending-deliveries')->assertSuccessful();
        $this->artisan('app:reconcile-pending-deliveries')->assertSuccessful();

        $reminder = CustomerNotification::query()->where('event', SupplierGroupNotifier::EVENT_SLA_REMINDER)->sole();
        $this->assertSame(self::GROUP, $reminder->chat_id);
        $this->assertStringContainsString("PERINGATAN* {$order->order_number}", $reminder->message);
        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }
}
