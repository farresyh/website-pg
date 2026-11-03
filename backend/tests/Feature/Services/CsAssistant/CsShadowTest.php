<?php

namespace Tests\Feature\Services\CsAssistant;

use App\Console\Commands\CsAssistant\PruneCsMessagesCommand;
use App\Jobs\DraftCsReplyJob;
use App\Jobs\JudgeCsDraftJob;
use App\Jobs\WriteCsSuggestionJob;
use App\Models\Affiliate;
use App\Models\CsAssistantSettings;
use App\Models\CsBotDraft;
use App\Models\CsIgnoredChat;
use App\Models\CsKnowledgeEntry;
use App\Models\CsKnowledgeSuggestion;
use App\Models\CsMessage;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Services\CsAssistant\Shadow\CsShadowDrafter;
use App\Services\CsAssistant\Shadow\CsShadowJudge;
use App\Services\CsAssistant\Shadow\CsSuggestionWriter;
use App\Services\Llm\ChatModels;
use App\Services\Llm\FakeChatModel;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-136 PR 3b: Shadow drafts one answer per customer burst and sends
 * nothing; the nightly judge pairs it with staff's reply; the writer rewrites
 * suggestions; the daily cost cap switches the mode Off.
 */
class CsShadowTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 'chat-a';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.storefront.url' => 'https://pekangame.com',
            'services.onboarding.url' => 'https://onboarding.pekangame.space',
            'services.anthropic.api_key' => 'test-key',
        ]);
        $this->primaryAffiliate();
        CsAssistantSettings::current()->update(['mode' => CsAssistantSettings::MODE_SHADOW]);
        $this->travelTo('2026-11-05 04:00:00');
    }

    private function msg(string $sender, ?string $body, string $at, array $extra = [], string $chat = self::CHAT): CsMessage
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

    private function fake(string $model, array $script): FakeChatModel
    {
        $fake = new FakeChatModel($script);
        app(ChatModels::class)->fake($model, $fake);

        return $fake;
    }

    private function answer(array $payload = []): FakeChatModel
    {
        return $this->fake('claude-haiku-5-5', [FakeChatModel::json($payload + [
            'action' => 'reply', 'reply' => 'Boleh bayar guna FPX boss 🙏', 'category' => 'payment', 'entry_ids' => [],
        ])]);
    }

    public function test_only_shadow_queues_a_draft_after_the_burst_wait(): void
    {
        Queue::fake();
        $m = $this->msg(CsMessage::SENDER_CUSTOMER, 'boleh bayar guna tng?', '2026-11-05 04:00:00');

        app(CsShadowDrafter::class)->onCustomerMessage($m);
        Queue::assertPushedOn('cs-assistant', DraftCsReplyJob::class, fn (DraftCsReplyJob $j) => $j->messageId === $m->id && $j->delay !== null);

        Queue::fake();
        CsAssistantSettings::current()->update(['mode' => CsAssistantSettings::MODE_OFF]);
        app(CsShadowDrafter::class)->onCustomerMessage($m);
        Queue::assertNothingPushed();
    }

    public function test_the_last_message_drafts_once_for_the_whole_burst_and_nothing_is_sent(): void
    {
        $first = $this->msg(CsMessage::SENDER_CUSTOMER, 'bang', '2026-11-05 03:59:00');
        $last = $this->msg(CsMessage::SENDER_CUSTOMER, 'boleh bayar guna tng?', '2026-11-05 03:59:20');
        $fake = $this->answer();

        $this->assertNull(app(CsShadowDrafter::class)->draft($first->id), 'an older message of the burst never drafts');
        $draft = app(CsShadowDrafter::class)->draft($last->id);

        $this->assertSame("bang\nboleh bayar guna tng?", $draft->question);
        $this->assertSame($first->id, $draft->first_message_id);
        $this->assertSame('reply', $draft->action);
        $this->assertSame('claude-haiku-5-5', $draft->model);
        $this->assertGreaterThan(0, $draft->cost_usd_micros);
        $this->assertCount(1, $fake->requests);
        $this->assertNull(app(CsShadowDrafter::class)->draft($last->id), 'a redelivered event drafts nothing more');
        $this->assertSame(0, CustomerNotification::query()->count(), 'Shadow sends nothing');
    }

    public function test_no_draft_for_an_order_number_alone_an_ignored_chat_or_outreach(): void
    {
        $orderOnly = $this->msg(CsMessage::SENDER_CUSTOMER, '[ORDER]', '2026-11-05 03:59:00', ['order_numbers' => ['PG-ABCDEF123456']]);
        $this->assertNull(app(CsShadowDrafter::class)->draft($orderOnly->id));

        $ignored = $this->msg(CsMessage::SENDER_CUSTOMER, 'harga?', '2026-11-05 03:59:00', [], 'chat-b');
        CsIgnoredChat::query()->create(['chat_hash' => str_pad('chat-b', 64, '0')]);
        $this->assertNull(app(CsShadowDrafter::class)->draft($ignored->id));

        $this->msg(CsMessage::SENDER_STAFF, 'Salam boss, nak jadi reseller?', '2026-11-05 03:00:00', [], 'chat-c');
        $outreach = $this->msg(CsMessage::SENDER_CUSTOMER, 'boleh', '2026-11-05 03:59:00', [], 'chat-c');
        $this->assertNull(app(CsShadowDrafter::class)->draft($outreach->id));

        $this->assertSame(0, CsBotDraft::query()->count());
    }

    public function test_a_failed_order_is_looked_up_and_forced_to_a_handover_under_its_own_brand(): void
    {
        $brand = Affiliate::query()->create(['business_name' => 'OhahaStore', 'markup_pct' => 0, 'status' => 'active']);
        $order = Order::factory()->forAffiliate($brand)->create(['delivery_status' => DeliveryStatus::Failed, 'paid_at' => now()->subMinutes(10)]);
        $m = $this->msg(CsMessage::SENDER_CUSTOMER, '[ORDER] tak masuk lagi', '2026-11-05 03:59:00', ['order_numbers' => [$order->order_number]]);
        $fake = $this->answer();

        $draft = app(CsShadowDrafter::class)->draft($m->id);

        $this->assertSame('handover', $draft->action);
        $this->assertSame('order_failed', $draft->handover_reason);
        $this->assertSame($brand->id, $draft->affiliate_id);
        $this->assertStringContainsString('OhahaStore', $fake->requests[0]->system);
        $this->assertStringContainsString('delivery: failed', $fake->requests[0]->messages[0]['text']);
    }

    public function test_reaching_the_daily_cap_switches_the_mode_off_and_drafts_nothing(): void
    {
        CsAssistantSettings::current()->update(['daily_cost_cap_sen' => 100]);
        // RM1 at 4.5 MYR/USD is about $0.23 = 230,000 micros.
        CsBotDraft::query()->create($this->draftRow(['cost_usd_micros' => 300_000, 'last_message_id' => 999]));
        $m = $this->msg(CsMessage::SENDER_CUSTOMER, 'harga ml?', '2026-11-05 03:59:00');

        $this->assertNull(app(CsShadowDrafter::class)->draft($m->id));

        $settings = CsAssistantSettings::current();
        $this->assertSame(CsAssistantSettings::MODE_OFF, $settings->mode);
        $this->assertNotNull($settings->cost_cap_tripped_at);
    }

    private function draftRow(array $extra = []): array
    {
        return $extra + [
            'chat_hash' => str_pad(self::CHAT, 64, '0'), 'last_message_id' => 1, 'first_message_id' => 1, 'question' => 'q',
            'asked_at' => now(), 'model' => 'claude-haiku-5-5', 'action' => 'reply', 'reply' => 'r', 'category' => 'payment',
        ];
    }

    public function test_the_judge_grades_against_staffs_reply_with_a_different_model(): void
    {
        $last = $this->msg(CsMessage::SENDER_CUSTOMER, 'boleh bayar guna tng?', '2026-11-05 01:00:00');
        $this->msg(CsMessage::SENDER_SYSTEM, null, '2026-11-05 01:00:30');
        $this->msg(CsMessage::SENDER_STAFF, 'Boleh boss, DuitNow QR', '2026-11-05 01:02:00');
        $this->msg(CsMessage::SENDER_CUSTOMER, 'ok thanks', '2026-11-05 01:03:00');
        $this->msg(CsMessage::SENDER_STAFF, 'sama-sama', '2026-11-05 01:04:00');
        $draft = CsBotDraft::query()->create($this->draftRow(['last_message_id' => $last->id, 'first_message_id' => $last->id, 'asked_at' => $last->sent_at]));
        $judge = $this->fake('claude-sonnet-5-5', [FakeChatModel::json(['grade' => 'same', 'note' => 'Both say e-wallets work.'])]);

        $due = app(CsShadowJudge::class)->due(now()->toImmutable());
        $this->assertSame([$draft->id], $due->pluck('id')->all());
        app(CsShadowJudge::class)->judge($draft);

        $draft->refresh();
        $this->assertSame('Boleh boss, DuitNow QR', $draft->staff_answer, 'only the reply before the customer wrote again');
        $this->assertSame('same', $draft->grade);
        $this->assertSame('claude-sonnet-5-5', $draft->judge_model);
        $this->assertNotNull($draft->judged_at);
        $this->assertStringContainsString('What staff actually replied', $judge->requests[0]->messages[0]['text']);
    }

    public function test_no_staff_reply_closes_ungraded_and_an_image_reply_is_unclear(): void
    {
        $a = $this->msg(CsMessage::SENDER_CUSTOMER, 'harga?', '2026-11-05 01:00:00');
        $unanswered = CsBotDraft::query()->create($this->draftRow(['last_message_id' => $a->id, 'asked_at' => $a->sent_at]));

        $b = $this->msg(CsMessage::SENDER_CUSTOMER, 'mana nak tengok id?', '2026-11-05 01:00:00', [], 'chat-b');
        $this->msg(CsMessage::SENDER_STAFF, null, '2026-11-05 01:01:00', [], 'chat-b');
        $image = CsBotDraft::query()->create($this->draftRow(['chat_hash' => str_pad('chat-b', 64, '0'), 'last_message_id' => $b->id, 'asked_at' => $b->sent_at]));

        app(CsShadowJudge::class)->judge($unanswered);
        app(CsShadowJudge::class)->judge($image);

        $this->assertNull($unanswered->refresh()->grade);
        $this->assertNotNull($unanswered->judged_at);
        $this->assertSame('unclear', $image->refresh()->grade);
    }

    public function test_a_draft_still_inside_its_answer_window_is_not_due(): void
    {
        $m = $this->msg(CsMessage::SENDER_CUSTOMER, 'harga?', '2026-11-05 03:00:00');
        CsBotDraft::query()->create($this->draftRow(['last_message_id' => $m->id, 'asked_at' => $m->sent_at]));

        $this->assertCount(0, app(CsShadowJudge::class)->due(now()->toImmutable()));
    }

    public function test_the_writer_rewrites_a_pending_suggestion_and_points_at_an_existing_entry(): void
    {
        $entry = CsKnowledgeEntry::query()->create(['category' => 'payment', 'questions' => ['bayar guna apa?'], 'answer' => 'FPX', 'status' => CsKnowledgeEntry::STATUS_APPROVED]);
        $suggestion = CsKnowledgeSuggestion::query()->create([
            'chat_hash' => str_pad(self::CHAT, 64, '0'), 'first_message_id' => 1, 'question' => 'boleh guna tng tak bang [NUMBER]',
            'answer' => 'boleh boss, modal kami sama je', 'asked_at' => now(), 'status' => CsKnowledgeSuggestion::STATUS_PENDING,
        ]);
        $fake = $this->fake('claude-sonnet-5-5', [FakeChatModel::json(['question' => 'Boleh bayar guna TNG?', 'answer' => 'Boleh boss, guna DuitNow QR 🙏', 'category' => 'payment', 'entry_id' => $entry->id])]);

        app(CsSuggestionWriter::class)->write($suggestion);

        $suggestion->refresh();
        $this->assertSame('Boleh bayar guna TNG?', $suggestion->ai_question);
        $this->assertSame($entry->id, $suggestion->ai_entry_id);
        $this->assertSame('payment', $suggestion->ai_category);
        $this->assertNotNull($suggestion->ai_written_at);
        $this->assertStringContainsString("[{$entry->id}] payment", $fake->requests[0]->system);

        app(CsSuggestionWriter::class)->write($suggestion);
        $this->assertCount(1, $fake->requests, 'written once');
    }

    public function test_the_nightly_commands_queue_one_job_per_draft_and_suggestion(): void
    {
        Queue::fake();
        $m = $this->msg(CsMessage::SENDER_CUSTOMER, 'harga?', '2026-11-05 01:00:00');
        CsBotDraft::query()->create($this->draftRow(['last_message_id' => $m->id, 'asked_at' => $m->sent_at]));
        CsKnowledgeSuggestion::query()->create([
            'chat_hash' => str_pad('x', 64, '0'), 'first_message_id' => 5, 'question' => 'q', 'answer' => 'a', 'asked_at' => now(), 'status' => CsKnowledgeSuggestion::STATUS_PENDING,
        ]);

        $this->artisan('cs:judge-drafts')->assertSuccessful();
        $this->artisan('cs:suggest-knowledge')->assertSuccessful();

        Queue::assertPushedOn('cs-assistant', JudgeCsDraftJob::class);
        Queue::assertPushedOn('cs-assistant', WriteCsSuggestionJob::class);
    }

    public function test_drafts_are_pruned_after_90_days(): void
    {
        $old = CsBotDraft::query()->create($this->draftRow(['last_message_id' => 1]));
        $old->forceFill(['created_at' => now()->subDays(PruneCsMessagesCommand::DRAFT_RETENTION_DAYS + 1)])->save();
        CsBotDraft::query()->create($this->draftRow(['last_message_id' => 2]));

        $this->artisan('cs:prune-messages')->assertSuccessful();

        $this->assertSame([2], CsBotDraft::query()->pluck('last_message_id')->all());
    }

    public function test_the_judge_is_never_a_test_candidate(): void
    {
        $this->assertNotContains('claude-sonnet-5-5', app(ChatModels::class)->ids());
        $this->assertTrue(app(ChatModels::class)->has('claude-sonnet-5-5'));
    }
}
