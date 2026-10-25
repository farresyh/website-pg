<?php

namespace Tests\Feature;

use App\Jobs\Reseller\SendResellerBotReplyJob;
use App\Jobs\SendCustomerWhatsAppJob;
use App\Models\AdminUser;
use App\Models\CustomerNotification;
use App\Models\JokiOrder;
use App\Models\JokiProgressUpdate;
use App\Models\JokiSetting;
use App\Models\JokiTier;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\Supplier;
use App\Models\WhatsappContact;
use App\Services\Joki\JokiAssignmentService;
use App\Services\Joki\JokiProgressService;
use App\Services\Notification\CustomerNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\JokiFixtures;
use Tests\Support\OrderCredentialFixtures;
use Tests\Support\OrderFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-126 PR 3 (decisions 10 and 21): the penjoki's `.progress`, the admin's
 * progress update, the order's progress history and current rank, the
 * customer's Track Order view, and the paced WhatsApp update — opted-in
 * customers only, the first at once, then at most one per interval,
 * always carrying the latest rank.
 */
class Adr126JokiProgressTest extends TestCase
{
    use JokiFixtures;
    use OrderCredentialFixtures;
    use OrderFulfillmentFixtures;
    use RefreshDatabase;

    private const PHONE = '60123456789';

    private Supplier $amir;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'services.openwa.base_url' => 'http://openwa.test',
            'services.openwa.session_id' => 'bot-session',
            'services.openwa.api_key' => 'bot-key',
            'services.openwa.cs_session_id' => 'cs-session',
            'services.openwa.cs_api_key' => 'cs-key',
            'services.openwa.webhook_secret' => 'test-secret',
            'services.openwa.webhook_signature_header' => 'X-OpenWA-Signature',
            'services.openwa.webhook_signature_algo' => 'sha256',
        ]);
        PlatformSettings::current()->update(['whatsapp_notifications_enabled' => true]);

        $this->order = $this->pendingJokiOrder(['customer_phone' => '012-345 6789']);
        JokiOrder::query()->create([
            'order_id' => $this->order->id,
            'from_rank' => ['tier_id' => $this->tierId('Epic'), 'tier_name' => 'Epic', 'division' => 5, 'stars' => 0, 'label' => 'Epic V 0★'],
            'to_rank' => ['tier_id' => $this->tierId('Legend'), 'tier_name' => 'Legend', 'division' => 5, 'stars' => 0, 'label' => 'Legend V 0★'],
            'quote' => ['total_rm' => 50], 'payout_share_pct' => 100,
        ]);
        $this->amir = $this->penjoki('amir');
        app(JokiAssignmentService::class)->assign($this->order, $this->amir->id, 'Aina');
    }

    private function tierId(string $name): int
    {
        return JokiTier::query()->where('name', $name)->value('id');
    }

    private function say(string $text, string $group = 'amir@g.us'): TestResponse
    {
        $body = json_encode([
            'event' => 'message.received',
            'sessionId' => 'bot-session',
            'data' => ['kind' => 'group', 'chatId' => $group, 'body' => $text, 'id' => uniqid('m'), 'author' => '60111111111@c.us', 'fromMe' => false],
        ]);

        return $this->call('POST', '/api/webhooks/openwa', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OPENWA_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        ], $body)->assertOk();
    }

    /** @return list<string> */
    private function botReplies(): array
    {
        $texts = [];
        Queue::assertPushed(SendResellerBotReplyJob::class, function (SendResellerBotReplyJob $job) use (&$texts) {
            $texts[] = $job->text;

            return true;
        });

        return $texts;
    }

    private function progressMessages()
    {
        return CustomerNotification::query()->where('order_id', $this->order->id)->where('event', CustomerNotificationService::EVENT_JOKI_PROGRESS)->orderBy('id')->get();
    }

    private function sendText(CustomerNotification $row): ?string
    {
        $sent = null;
        Http::fake(['openwa.test/*' => function ($request) use (&$sent) {
            $sent = $request['text'];

            return Http::response(['ok' => true]);
        }]);
        (new SendCustomerWhatsAppJob($row->id))->handle();

        return $sent;
    }

    private function record(array $rank): void
    {
        app(JokiProgressService::class)->record($this->order->fresh(), $rank, 'Aina Admin', 'admin');
    }

    // --- Decision 10: `.progress` in the group --------------------------------

    public function test_progress_in_the_group_updates_the_rank_history_and_clock(): void
    {
        $this->travel(10)->minutes();
        $this->say(".progress {$this->order->order_number} epic iii 2");

        $joki = JokiOrder::query()->sole();
        $this->assertSame('Epic III 2★', $joki->current_rank['label']);
        $update = JokiProgressUpdate::query()->sole();
        $this->assertSame(['Epic III 2★', 'wa:60111111111', 'supplier_bot'], [$update->rank['label'], $update->actor, $update->channel]);
        $this->assertEqualsWithDelta(now()->timestamp, $this->order->fresh()->manual_activity_at->timestamp, 2);
        $this->assertStringContainsString('Epic III 2★', collect($this->botReplies())->last());
    }

    public function test_progress_accepts_mythic_star_counts_short_names_and_digits(): void
    {
        $this->say(".progress {$this->order->order_number} leg 2 4");
        $this->assertSame('Legend II 4★', JokiOrder::query()->sole()->current_rank['label']);

        $this->say(".progress {$this->order->order_number} Mythic 30");
        $this->assertSame('Mythical Honor 30★', JokiOrder::query()->sole()->fresh()->current_rank['label']);

        $this->say(".progress {$this->order->order_number} honor 31");
        $this->assertSame('Mythical Honor 31★', JokiOrder::query()->sole()->fresh()->current_rank['label']);
    }

    public function test_a_bad_rank_or_someone_elses_order_is_answered_and_changes_nothing(): void
    {
        $this->say(".progress {$this->order->order_number} Epic VI 2");
        $this->say(".progress {$this->order->order_number} Epic III 9");
        $this->say(".progress {$this->order->order_number} Platinum 2 1");
        $this->say(".progress {$this->order->order_number}");

        $bala = $this->penjoki('bala');
        $this->say(".progress {$this->order->order_number} Epic III 2", 'bala@g.us');

        $this->assertSame(0, JokiProgressUpdate::query()->count());
        $replies = $this->botReplies();
        $this->assertStringContainsString('tiada dalam senarai', end($replies));
        $this->assertNotSame($bala->id, $this->order->fresh()->supplier_id);
    }

    public function test_the_admin_updates_progress_from_order_detail(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/orders/{$this->order->id}/joki/progress", ['tier_id' => $this->tierId('Legend'), 'division' => 4, 'stars' => 1])
            ->assertOk()->assertJsonPath('current_rank.label', 'Legend IV 1★');
        $this->postJson("/api/orders/{$this->order->id}/joki/progress", ['tier_id' => $this->tierId('Legend'), 'division' => 9, 'stars' => 1])
            ->assertJsonValidationErrors('rank');

        $this->assertSame([$admin->name, 'admin'], [JokiProgressUpdate::query()->sole()->actor, JokiProgressUpdate::query()->sole()->channel]);
    }

    public function test_progress_is_only_for_a_joki_order_a_penjoki_is_playing(): void
    {
        $pool = $this->pendingJokiOrder(['order_number' => 'PG-JOKIPOOL001']);

        try {
            app(JokiProgressService::class)->record($pool, ['tier_id' => $this->tierId('Epic'), 'division' => 3, 'stars' => 2], 'Aina', 'admin');
            $this->fail('Progress on a pool order was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order', $e->errors());
        }
    }

    // --- Decision 10 / S23: the customer's WhatsApp update ---------------------

    public function test_no_message_for_a_customer_who_never_opted_in(): void
    {
        $this->record(['tier_id' => $this->tierId('Epic'), 'division' => 3, 'stars' => 2]);

        $this->assertCount(0, $this->progressMessages());
    }

    public function test_the_first_update_goes_at_once_then_one_per_interval_carrying_the_latest(): void
    {
        WhatsappContact::query()->create(['phone' => self::PHONE, 'opted_in_at' => now(), 'opt_in_source' => 'message']);

        $this->record(['tier_id' => $this->tierId('Epic'), 'division' => 3, 'stars' => 2]);
        $first = $this->progressMessages()->sole();
        $this->assertSame(self::PHONE, $first->phone);
        $this->assertSame(CustomerNotification::SESSION_CUSTOMER_SUPPORT, $first->session);
        $text = $this->sendText($first);
        $this->assertStringContainsString('Epic III 2★', $text);
        $this->assertStringContainsString('Legend V 0★', $text);

        // Two more inside the interval: one queued message, sent with the latest.
        $this->travel(30)->minutes();
        $this->record(['tier_id' => $this->tierId('Epic'), 'division' => 2, 'stars' => 1]);
        $this->travel(30)->minutes();
        $this->record(['tier_id' => $this->tierId('Epic'), 'division' => 1, 'stars' => 3]);

        $this->assertCount(2, $this->progressMessages());
        $second = $this->progressMessages()->last();
        Queue::assertPushed(SendCustomerWhatsAppJob::class, fn (SendCustomerWhatsAppJob $job) => $job->notificationId === $second->id
            && $job->delay !== null && $job->delay->getTimestamp() >= $first->fresh()->sent_at->copy()->addMinutes(120)->getTimestamp() - 1);
        $this->assertStringContainsString('Epic I 3★', $this->sendText($second));
    }

    public function test_the_interval_comes_from_the_panel(): void
    {
        WhatsappContact::query()->create(['phone' => self::PHONE, 'opted_in_at' => now(), 'opt_in_source' => 'message']);
        JokiSetting::current()->update(['progress_message_interval_minutes' => 30]);

        $this->record(['tier_id' => $this->tierId('Epic'), 'division' => 3, 'stars' => 2]);
        $this->sendText($this->progressMessages()->sole());

        $this->travel(31)->minutes();
        $this->record(['tier_id' => $this->tierId('Epic'), 'division' => 2, 'stars' => 1]);

        $second = $this->progressMessages()->last();
        Queue::assertPushed(SendCustomerWhatsAppJob::class, fn (SendCustomerWhatsAppJob $job) => $job->notificationId === $second->id
            && ($job->delay === null || $job->delay->getTimestamp() <= now()->addMinute()->getTimestamp()));
    }

    public function test_a_queued_update_is_dropped_if_the_customer_stops_or_the_order_finishes(): void
    {
        WhatsappContact::query()->create(['phone' => self::PHONE, 'opted_in_at' => now(), 'opt_in_source' => 'message']);
        $this->record(['tier_id' => $this->tierId('Epic'), 'division' => 3, 'stars' => 2]);
        $this->sendText($this->progressMessages()->sole());
        $this->record(['tier_id' => $this->tierId('Epic'), 'division' => 2, 'stars' => 1]);
        $queued = $this->progressMessages()->last();

        WhatsappContact::query()->update(['opted_out_at' => now()]);

        $this->assertNull($this->sendText($queued));
        $this->assertSame(CustomerNotification::STATUS_SKIPPED, $queued->fresh()->status);
    }

    // --- Track Order -----------------------------------------------------------

    public function test_track_order_shows_the_climb_and_the_current_rank(): void
    {
        $this->record(['tier_id' => $this->tierId('Epic'), 'division' => 3, 'stars' => 2]);

        $this->getJson("/api/track-order/{$this->order->order_number}")
            ->assertOk()
            ->assertJsonPath('joki.from', 'Epic V 0★')
            ->assertJsonPath('joki.to', 'Legend V 0★')
            ->assertJsonPath('joki.current', 'Epic III 2★')
            ->assertJsonMissingPath('joki.payout_share_pct');
    }
}
