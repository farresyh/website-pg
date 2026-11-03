<?php

namespace Tests\Feature\Services\CsAssistant;

use App\Models\CsKnowledgeEntry;
use App\Models\Game;
use App\Models\Supplier;
use App\Services\CsAssistant\CsAnswerer;
use App\Services\CsAssistant\CsReplyGuard;
use App\Services\Llm\ChatModelException;
use App\Services\Llm\ChatModels;
use App\Services\Llm\ChatRequest;
use App\Services\Llm\ChatResponse;
use App\Services\Llm\FakeChatModel;
use App\Services\Llm\GeminiChatModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-136 PR 3a (decisions 3–7, 13, 14, 17, 25, 33): the assistant's draft.
 * Code decides the brand, what an order state means and whether a reply
 * leaks; the model only writes from approved knowledge.
 */
class CsAnswererTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'gemini-3.1-flash-lite';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.storefront.url' => 'https://pekangame.com', 'services.onboarding.url' => 'https://onboarding.pekangame.space']);
        $this->primaryAffiliate();
    }

    private function fake(array $script): FakeChatModel
    {
        $fake = new FakeChatModel($script);
        app(ChatModels::class)->fake(self::MODEL, $fake);

        return $fake;
    }

    private function draft(array $messages, ?array $facts = null): array
    {
        return app(CsAnswerer::class)->draft(self::MODEL, $messages, null, $facts);
    }

    public function test_a_reply_from_approved_knowledge_passes_through(): void
    {
        $entry = CsKnowledgeEntry::query()->create([
            'category' => 'order_status', 'questions' => ['Kenapa bonus First Topup tak masuk?'],
            'answer' => 'Bonus First Topup hanya sekali bagi setiap akaun.', 'status' => CsKnowledgeEntry::STATUS_APPROVED,
        ]);
        CsKnowledgeEntry::query()->create(['category' => 'other', 'questions' => ['lama'], 'answer' => 'ARCHIVED ANSWER', 'status' => CsKnowledgeEntry::STATUS_ARCHIVED]);
        CsKnowledgeEntry::query()->create(['category' => 'other', 'questions' => ['luput'], 'answer' => 'EXPIRED ANSWER', 'status' => CsKnowledgeEntry::STATUS_APPROVED, 'valid_until' => now()->subDay()]);

        $fake = $this->fake([FakeChatModel::json(['action' => 'reply', 'reply' => 'Bonus First Topup sekali je per akaun boss 🙏', 'category' => 'order_status', 'entry_ids' => [$entry->id]])]);

        $draft = $this->draft(['beli 250+250 dapat 250 je, call me 0123456789']);

        $this->assertSame('reply', $draft['action']);
        $this->assertSame([$entry->id], $draft['entry_ids']);
        $this->assertSame([], $draft['violations']);

        $system = $fake->requests[0]->system;
        $this->assertStringContainsString('Bonus First Topup hanya sekali bagi setiap akaun.', $system);
        $this->assertStringNotContainsString('ARCHIVED ANSWER', $system);
        $this->assertStringNotContainsString('EXPIRED ANSWER', $system);
        // Decision 13: the phone number never leaves.
        $this->assertStringNotContainsString('0123456789', $fake->requests[0]->messages[0]['text']);
    }

    public function test_game_info_gives_the_brand_link_and_no_price(): void
    {
        // Production stores important_notes as a list (Game casts it to an array): run #1 crashed on it.
        Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true, 'description' => '<p>Top up diamonds</p>', 'important_notes' => ['User ID : 123 | Server ID : 2005', 'Delays may happen']]);
        $fake = $this->fake([
            FakeChatModel::call('game_info', ['game' => 'mobile']),
            FakeChatModel::json(['action' => 'reply', 'reply' => 'Harga terkini ada kat https://pekangame.com/order/mobile-legends ya boss', 'category' => 'price_package', 'entry_ids' => []]),
        ]);

        $draft = $this->draft(['harga diamond ml berapa']);

        $this->assertSame('reply', $draft['action']);
        $this->assertSame(2, $draft['calls']);
        $tool = $fake->requests[1]->messages[2];
        $this->assertSame('tool', $tool['role']);
        $this->assertSame('https://pekangame.com/order/mobile-legends', $tool['result']['games'][0]['page']);
        $this->assertSame('Top up diamonds', $tool['result']['games'][0]['description']);
        $this->assertSame('User ID : 123 | Server ID : 2005 Delays may happen', $tool['result']['games'][0]['important_notes']);
        $this->assertArrayNotHasKey('price', $tool['result']['games'][0]);
    }

    public function test_a_failed_or_late_order_is_always_handed_over_whatever_the_model_says(): void
    {
        $this->fake([
            FakeChatModel::json(['action' => 'reply', 'reply' => 'Jap ya boss', 'category' => 'order_failed', 'entry_ids' => []]),
            FakeChatModel::json(['action' => 'reply', 'reply' => 'Tunggu sekejap', 'category' => 'order_status', 'entry_ids' => []]),
            FakeChatModel::json(['action' => 'reply', 'reply' => 'Tengah diproses', 'category' => 'order_status', 'entry_ids' => []]),
        ]);

        $failed = $this->draft(['order gagal'], ['payment_status' => 'paid', 'delivery_status' => 'failed', 'minutes_since_paid' => 3]);
        $late = $this->draft(['x masuk lagi'], ['payment_status' => 'paid', 'delivery_status' => 'processing', 'minutes_since_paid' => 31]);
        $fine = $this->draft(['x masuk lagi'], ['payment_status' => 'paid', 'delivery_status' => 'processing', 'minutes_since_paid' => 20]);

        $this->assertSame(['handover', 'order_failed'], [$failed['action'], $failed['handover_reason']]);
        $this->assertSame(['handover', 'late'], [$late['action'], $late['handover_reason']]);
        $this->assertSame('reply', $fine['action']);
    }

    public function test_a_manual_game_uses_its_own_expected_time(): void
    {
        $this->fake([FakeChatModel::json(['action' => 'reply', 'reply' => 'Tengah diproses', 'category' => 'order_status', 'entry_ids' => []])]);

        $draft = $this->draft(['x masuk lagi'], ['payment_status' => 'paid', 'delivery_status' => 'pending', 'minutes_since_paid' => 45, 'expected_minutes' => 120]);

        $this->assertSame('reply', $draft['action']);
    }

    public function test_a_reply_that_leaks_is_never_passed(): void
    {
        Supplier::query()->create(['name' => 'Kedai Borong X', 'slug' => 'kedaix', 'driver' => 'manual', 'is_active' => true, 'api_config' => []]);
        $this->fake([FakeChatModel::json(['action' => 'reply', 'reply' => 'Kami ambil dari kedaix, untung kami 10% je, cuba juga https://othershop.com', 'category' => 'other', 'entry_ids' => []])]);

        $draft = $this->draft(['korang ambil dari mana?']);

        $this->assertSame(['handover', 'guard'], [$draft['action'], $draft['handover_reason']]);
        $this->assertContains('word: kedaix', $draft['violations']);
        $this->assertContains('word: untung', $draft['violations']);
        $this->assertContains('percentage', $draft['violations']);
        $this->assertContains('link: othershop.com', $draft['violations']);
    }

    public function test_the_guard_allows_the_brand_and_the_application_links_only(): void
    {
        $guard = app(CsReplyGuard::class);

        $this->assertSame([], $guard->violations('Boleh apply kat https://onboarding.pekangame.space/apply atau tengok www.pekangame.com ya. RM8.44 sahaja.', 'pekangame.com'));
        $this->assertSame([], $guard->violations('Bonus masuk sekali je per akaun.Semak dalam game ya', 'pekangame.com'));
        $this->assertSame(['link: pekangame.com'], $guard->violations('Tengok pekangame.com', 'ohahastore.com'));
        // Punctuation after a link is not part of the host (run #1).
        $this->assertSame([], $guard->violations('Kami PekanGame (https://pekangame.com) ya. Lawati https://pekangame.com, pilih game. Atau pekangame.com.', 'pekangame.com'));
    }

    public function test_a_model_that_keeps_calling_tools_must_answer_on_the_last_call(): void
    {
        $fake = $this->fake([
            FakeChatModel::call('game_info', ['game' => 'a']),
            FakeChatModel::call('game_info', ['game' => 'b']),
            FakeChatModel::call('game_info', ['game' => 'c']),
            FakeChatModel::json(['action' => 'reply', 'reply' => 'Boleh tengok kat laman kami ya', 'category' => 'other', 'entry_ids' => []]),
        ]);

        $draft = $this->draft(['ada game baru?']);

        $this->assertSame('reply', $draft['action']);
        $this->assertSame(4, $draft['calls']);
        $this->assertNotSame([], $fake->requests[2]->tools);
        $this->assertSame([], $fake->requests[3]->tools);
    }

    public function test_a_model_error_or_bad_json_becomes_a_handover(): void
    {
        $this->fake([new ChatModelException('timeout'), new ChatResponse('not json', [], 10, 5)]);

        $this->assertSame(['handover', 'model_error'], [($a = $this->draft(['hi']))['action'], $a['handover_reason']]);
        $this->assertSame('timeout', $a['error']);
        $this->assertSame(['handover', 'model_error'], [($b = $this->draft(['hi']))['action'], $b['handover_reason']]);
    }

    public function test_the_gemini_adapter_sends_the_key_in_a_header_and_replays_parts(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['text' => 'thinking...', 'thought' => true],
                ['functionCall' => ['name' => 'game_info', 'args' => ['game' => 'ml']], 'thoughtSignature' => 'sig'],
            ]]]],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 5],
        ])]);

        $model = new GeminiChatModel('gemini-3.1-flash-lite', 'secret-key', 'https://g.test/v1beta');
        $response = $model->complete(new ChatRequest('sys', [['role' => 'user', 'text' => 'hi']], [['name' => 'game_info', 'description' => 'd', 'parameters' => ['type' => 'object']]], ['type' => 'object']));

        $this->assertNull($response->text);
        $this->assertSame([['name' => 'game_info', 'args' => ['game' => 'ml']]], $response->toolCalls);
        $this->assertSame(15, $response->outputTokens);
        $this->assertSame('sig', $response->parts[1]['thoughtSignature']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://g.test/v1beta/models/gemini-3.1-flash-lite:generateContent'
            && $r->header('x-goog-api-key') === ['secret-key']
            && ! str_contains($r->url(), 'secret-key')
            && $r['generationConfig']['responseMimeType'] === 'application/json'
            && $r['tools'][0]['functionDeclarations'][0]['name'] === 'game_info');
    }

    public function test_cost_is_priced_per_model(): void
    {
        // 1,000 in at $0.25/M + 1,000 out at $1.50/M = $0.00175 = 1,750 micros.
        $this->assertSame(1750, app(ChatModels::class)->costMicros('gemini-3.1-flash-lite', 1000, 1000));
    }
}
