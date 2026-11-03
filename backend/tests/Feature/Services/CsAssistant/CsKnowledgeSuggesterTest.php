<?php

namespace Tests\Feature\Services\CsAssistant;

use App\Models\Affiliate;
use App\Models\CsIgnoredChat;
use App\Models\CsKnowledgeSuggestion;
use App\Models\CsMessage;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Services\CsAssistant\CsKnowledgeSuggester;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-136 PR 2 (decisions 10, 16, 30): each night a finished CS conversation's
 * customer question and the staff answer that followed become a pending
 * knowledge suggestion, with no AI. Our own sends never count as an answer.
 */
class CsKnowledgeSuggesterTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-11-04 19:00:00');
    }

    private function msg(string $chat, string $sender, ?string $body, string $at, array $extra = []): CsMessage
    {
        return CsMessage::query()->create($extra + [
            'wa_message_id' => Str::random(20),
            'chat_hash' => str_pad($chat, 64, '0'),
            'direction' => $sender === CsMessage::SENDER_CUSTOMER ? 'in' : 'out',
            'sender' => $sender,
            'type' => $body === null ? 'image' : 'text',
            'body' => $body,
            'source' => CsMessage::SOURCE_WEBHOOK,
            'sent_at' => $at,
        ]);
    }

    private function brand(): Affiliate
    {
        return Affiliate::query()->create(['business_name' => 'OhahaStore', 'markup_pct' => 0, 'status' => 'active']);
    }

    private function suggest(): int
    {
        return app(CsKnowledgeSuggester::class)->run($this->now);
    }

    public function test_a_customer_burst_and_the_staff_reply_after_it_become_one_pending_suggestion(): void
    {
        $first = $this->msg('a', 'customer', 'bang sy beli 250+250 tapi masuk 250 je', '2026-11-04 09:10:00');
        $this->msg('a', 'customer', 'xde bonus pun', '2026-11-04 09:10:30');
        $this->msg('a', 'system', null, '2026-11-04 09:11:00');
        $this->msg('a', 'staff', 'First Topup tu sekali je per akaun boss', '2026-11-04 09:12:00');
        $this->msg('a', 'staff', 'boleh check kat tanda + sebelah diamond', '2026-11-04 09:13:00');

        $this->assertSame(1, $this->suggest());

        $s = CsKnowledgeSuggestion::query()->sole();
        $this->assertSame($first->id, $s->first_message_id);
        $this->assertSame("bang sy beli 250+250 tapi masuk 250 je\nxde bonus pun", $s->question);
        $this->assertSame("First Topup tu sekali je per akaun boss\nboleh check kat tanda + sebelah diamond", $s->answer);
        $this->assertSame(CsKnowledgeSuggestion::STATUS_PENDING, $s->status);
        $this->assertSame('2026-11-04 09:10:00', $s->asked_at->toDateTimeString());
    }

    public function test_running_twice_adds_nothing(): void
    {
        $this->msg('a', 'customer', 'macam mana nak beli', '2026-11-04 09:10:00');
        $this->msg('a', 'staff', 'pilih game, isi ID, bayar', '2026-11-04 09:11:00');

        $this->assertSame(1, $this->suggest());
        $this->assertSame(0, $this->suggest());
        $this->assertSame(1, CsKnowledgeSuggestion::query()->count());
    }

    public function test_a_chat_still_active_within_six_hours_waits_for_a_later_night(): void
    {
        $this->msg('a', 'customer', 'soalan', '2026-11-04 12:00:00');
        $this->msg('a', 'staff', 'jawapan', '2026-11-04 13:30:00');

        $this->assertSame(0, $this->suggest());

        $this->now = $this->now->addDay();
        $this->assertSame(1, $this->suggest());
    }

    public function test_staff_messages_after_two_hours_or_after_the_customer_writes_again_are_not_the_answer(): void
    {
        $this->msg('a', 'customer', 'soalan satu', '2026-11-04 01:00:00');
        $this->msg('a', 'staff', 'jawapan satu', '2026-11-04 01:05:00');
        $this->msg('a', 'staff', 'lewat sangat', '2026-11-04 03:30:00');
        $this->msg('a', 'customer', 'soalan dua', '2026-11-04 04:00:00');
        $this->msg('a', 'staff', 'jawapan dua', '2026-11-04 04:01:00');

        $this->assertSame(2, $this->suggest());

        $this->assertSame(
            [['soalan satu', 'jawapan satu'], ['soalan dua', 'jawapan dua']],
            CsKnowledgeSuggestion::query()->orderBy('id')->get()->map(fn ($s) => [$s->question, $s->answer])->all(),
        );
    }

    public function test_no_suggestion_without_a_text_answer_from_staff(): void
    {
        $this->msg('a', 'customer', 'tak dibalas', '2026-11-04 01:00:00');
        $this->msg('a', 'system', null, '2026-11-04 01:00:10');
        $this->msg('b', 'customer', 'dibalas dengan gambar', '2026-11-04 01:00:00');
        $this->msg('b', 'staff', null, '2026-11-04 01:01:00');

        $this->assertSame(0, $this->suggest());
    }

    public function test_a_customer_image_shows_as_a_placeholder(): void
    {
        $this->msg('a', 'customer', null, '2026-11-04 01:00:00');
        $this->msg('a', 'customer', 'ni resit sy', '2026-11-04 01:00:05');
        $this->msg('a', 'staff', 'ok kita check', '2026-11-04 01:02:00');

        $this->suggest();

        $this->assertSame("[image]\nni resit sy", CsKnowledgeSuggestion::query()->sole()->question);
    }

    public function test_a_chat_started_by_staff_is_outreach_and_skipped(): void
    {
        $this->msg('a', 'system', null, '2026-11-04 00:59:00');
        $this->msg('a', 'staff', 'Salam, kami PekanGame, nak jadi partner?', '2026-11-04 01:00:00');
        $this->msg('a', 'customer', 'berapa harga borong', '2026-11-04 01:10:00');
        $this->msg('a', 'staff', 'nanti saya bagi', '2026-11-04 01:11:00');

        $this->assertSame(0, $this->suggest());
    }

    public function test_a_chat_that_starts_with_our_receipt_is_not_outreach(): void
    {
        $this->msg('a', 'system', null, '2026-11-04 00:59:00');
        $this->msg('a', 'customer', 'tak masuk lagi', '2026-11-04 01:10:00');
        $this->msg('a', 'staff', 'jap check', '2026-11-04 01:11:00');

        $this->assertSame(1, $this->suggest());
    }

    public function test_an_ignored_chat_is_skipped(): void
    {
        $this->msg('a', 'customer', 'test founder', '2026-11-04 01:00:00');
        $this->msg('a', 'staff', 'test balas', '2026-11-04 01:01:00');
        CsIgnoredChat::query()->create(['chat_hash' => str_pad('a', 64, '0')]);

        $this->assertSame(0, $this->suggest());
    }

    public function test_the_brand_comes_from_an_order_number_in_the_pair(): void
    {
        $brand = $this->brand();
        $order = Order::factory()->forAffiliate($brand)->create();

        $this->msg('a', 'customer', 'order sy gagal', '2026-11-04 01:00:00', ['order_numbers' => [$order->order_number]]);
        $this->msg('a', 'staff', 'jap kita resend', '2026-11-04 01:02:00');

        $this->suggest();

        $s = CsKnowledgeSuggestion::query()->sole();
        $this->assertSame($brand->id, $s->affiliate_id);
        $this->assertSame([$order->order_number], $s->order_numbers);
    }

    public function test_without_an_order_number_the_brand_is_that_of_our_last_send_to_the_chat(): void
    {
        $brand = $this->brand();
        $order = Order::factory()->forAffiliate($brand)->create();
        $notification = CustomerNotification::query()->create([
            'session' => 'customer_support', 'event' => 'status_card', 'dedupe_key' => 'x', 'order_id' => $order->id,
            'chat_id' => '1@lid', 'message' => 'card', 'status' => 'sent',
        ]);

        $this->msg('a', 'system', null, '2026-11-04 00:30:00', ['customer_notification_id' => $notification->id]);
        $this->msg('a', 'customer', 'macam mana guna voucher', '2026-11-04 01:00:00');
        $this->msg('a', 'staff', 'masukkan kod masa checkout', '2026-11-04 01:02:00');

        $this->suggest();

        $this->assertSame($brand->id, CsKnowledgeSuggestion::query()->sole()->affiliate_id);
    }

    public function test_an_unknown_brand_is_left_empty(): void
    {
        $this->msg('a', 'customer', 'official store ke?', '2026-11-04 01:00:00');
        $this->msg('a', 'staff', 'ya boss', '2026-11-04 01:02:00');

        $this->suggest();

        $this->assertNull(CsKnowledgeSuggestion::query()->sole()->affiliate_id);
    }

    public function test_the_prune_drops_messages_and_suggestions_older_than_two_years_only(): void
    {
        $this->travelTo('2028-11-05 00:00:00');

        $old = $this->msg('a', 'customer', 'lama', '2026-11-04 01:00:00');
        $kept = $this->msg('a', 'customer', 'baru', '2026-11-06 01:00:00');
        CsKnowledgeSuggestion::query()->create([
            'chat_hash' => str_pad('a', 64, '0'), 'first_message_id' => $old->id, 'question' => 'q', 'answer' => 'a',
            'asked_at' => '2026-11-04 01:00:00', 'status' => CsKnowledgeSuggestion::STATUS_REJECTED,
        ]);
        CsKnowledgeSuggestion::query()->create([
            'chat_hash' => str_pad('a', 64, '0'), 'first_message_id' => $kept->id, 'question' => 'q', 'answer' => 'a',
            'asked_at' => '2026-11-06 01:00:00', 'status' => CsKnowledgeSuggestion::STATUS_PENDING,
        ]);

        $this->artisan('cs:prune-messages')->assertSuccessful();

        $this->assertSame([$kept->id], CsMessage::query()->pluck('id')->all());
        $this->assertSame([$kept->id], CsKnowledgeSuggestion::query()->pluck('first_message_id')->all());
    }
}
