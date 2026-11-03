<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\CsIgnoredChat;
use App\Models\CsKnowledgeEntry;
use App\Models\CsKnowledgeSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-136 decisions 2, 10, 16, 17 (PR 2): a super_admin turns suggestions
 * into approved knowledge, merges them, rejects them, or ignores a chat.
 */
class CsKnowledgeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function suggestion(array $overrides = []): CsKnowledgeSuggestion
    {
        static $n = 0;
        $n++;

        return CsKnowledgeSuggestion::query()->create($overrides + [
            'chat_hash' => str_pad('chat', 64, '0'),
            'first_message_id' => $n,
            'question' => 'bang kenapa bonus tak masuk',
            'answer' => 'First Topup sekali je per akaun',
            'asked_at' => now(),
            'status' => CsKnowledgeSuggestion::STATUS_PENDING,
        ]);
    }

    private function entry(array $overrides = []): CsKnowledgeEntry
    {
        return CsKnowledgeEntry::query()->create($overrides + [
            'category' => 'order_status',
            'questions' => ['Kenapa bonus First Topup tak masuk?'],
            'answer' => 'Bonus First Topup hanya sekali bagi setiap akaun.',
            'status' => CsKnowledgeEntry::STATUS_APPROVED,
        ]);
    }

    /** @return array<string, mixed> */
    private function entryPayload(array $overrides = []): array
    {
        return $overrides + [
            'category' => 'order_status',
            'questions' => ['Kenapa bonus First Topup tak masuk?', 'beli 250+250 dapat 250 je'],
            'answer' => 'Bonus First Topup MLBB hanya sekali bagi setiap akaun.',
            'affiliate_id' => null,
            'game_id' => null,
            'valid_until' => null,
        ];
    }

    public function test_a_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/cs-knowledge/suggestions')->assertForbidden();
        $this->getJson('/api/cs-knowledge/entries')->assertForbidden();
        $this->postJson('/api/cs-knowledge/entries', $this->entryPayload())->assertForbidden();
    }

    public function test_the_suggestion_list_filters_by_status_counts_each_and_hides_the_chat_hash(): void
    {
        $this->superAdmin();
        $this->suggestion();
        $this->suggestion(['status' => CsKnowledgeSuggestion::STATUS_REJECTED]);

        $res = $this->getJson('/api/cs-knowledge/suggestions?status=pending')->assertOk();

        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('counts.pending', 1);
        $res->assertJsonPath('counts.rejected', 1);
        $this->assertArrayNotHasKey('chat_hash', $res->json('data.0'));
    }

    public function test_approving_a_suggestion_creates_an_approved_entry_and_records_who(): void
    {
        $admin = $this->superAdmin();
        $s = $this->suggestion();

        $this->postJson("/api/cs-knowledge/suggestions/{$s->id}/approve", $this->entryPayload())->assertCreated();

        $entry = CsKnowledgeEntry::query()->sole();
        $this->assertSame(CsKnowledgeEntry::STATUS_APPROVED, $entry->status);
        $this->assertSame(['Kenapa bonus First Topup tak masuk?', 'beli 250+250 dapat 250 je'], $entry->questions);
        $this->assertSame($admin->id, $entry->approved_by);
        $this->assertNotNull($entry->approved_at);

        $s->refresh();
        $this->assertSame(CsKnowledgeSuggestion::STATUS_APPROVED, $s->status);
        $this->assertSame($entry->id, $s->cs_knowledge_entry_id);
        $this->assertSame($admin->id, $s->reviewed_by);
    }

    public function test_a_suggestion_already_reviewed_cannot_be_acted_on_again(): void
    {
        $this->superAdmin();
        $s = $this->suggestion(['status' => CsKnowledgeSuggestion::STATUS_REJECTED]);

        $this->postJson("/api/cs-knowledge/suggestions/{$s->id}/approve", $this->entryPayload())->assertUnprocessable();
        $this->postJson("/api/cs-knowledge/suggestions/{$s->id}/reject")->assertUnprocessable();
        $this->assertSame(0, CsKnowledgeEntry::query()->count());
    }

    public function test_merging_adds_the_question_as_a_variant_and_may_replace_the_answer(): void
    {
        $this->superAdmin();
        $entry = $this->entry();
        $s = $this->suggestion();

        $this->postJson("/api/cs-knowledge/suggestions/{$s->id}/merge", [
            'cs_knowledge_entry_id' => $entry->id,
            'question' => 'top up 50+50 dapat 50 je',
            'answer' => 'Bonus First Topup hanya sekali bagi setiap akaun, semak tanda + dalam game.',
        ])->assertOk();

        $entry->refresh();
        $this->assertSame(['Kenapa bonus First Topup tak masuk?', 'top up 50+50 dapat 50 je'], $entry->questions);
        $this->assertSame('Bonus First Topup hanya sekali bagi setiap akaun, semak tanda + dalam game.', $entry->answer);
        $this->assertSame(CsKnowledgeSuggestion::STATUS_MERGED, $s->refresh()->status);
        $this->assertSame($entry->id, $s->cs_knowledge_entry_id);
    }

    public function test_merging_without_an_answer_keeps_the_entry_answer_and_an_archived_entry_is_refused(): void
    {
        $this->superAdmin();
        $entry = $this->entry();
        $archived = $this->entry(['status' => CsKnowledgeEntry::STATUS_ARCHIVED]);
        $s = $this->suggestion();

        $this->postJson("/api/cs-knowledge/suggestions/{$s->id}/merge", ['cs_knowledge_entry_id' => $archived->id, 'question' => 'x'])
            ->assertUnprocessable();

        $this->postJson("/api/cs-knowledge/suggestions/{$s->id}/merge", ['cs_knowledge_entry_id' => $entry->id, 'question' => 'soalan baru'])
            ->assertOk();
        $this->assertSame('Bonus First Topup hanya sekali bagi setiap akaun.', $entry->refresh()->answer);
    }

    public function test_reject_marks_the_suggestion_rejected(): void
    {
        $this->superAdmin();
        $s = $this->suggestion();

        $this->postJson("/api/cs-knowledge/suggestions/{$s->id}/reject")->assertOk();

        $this->assertSame(CsKnowledgeSuggestion::STATUS_REJECTED, $s->refresh()->status);
    }

    public function test_ignoring_a_chat_closes_its_pending_suggestions_and_remembers_the_chat(): void
    {
        $admin = $this->superAdmin();
        $a = $this->suggestion();
        $b = $this->suggestion();
        $done = $this->suggestion(['status' => CsKnowledgeSuggestion::STATUS_REJECTED]);
        $other = $this->suggestion(['chat_hash' => str_pad('other', 64, '0')]);

        $this->postJson("/api/cs-knowledge/suggestions/{$a->id}/ignore-chat")->assertOk();

        $this->assertSame(CsKnowledgeSuggestion::STATUS_IGNORED, $a->refresh()->status);
        $this->assertSame(CsKnowledgeSuggestion::STATUS_IGNORED, $b->refresh()->status);
        $this->assertSame(CsKnowledgeSuggestion::STATUS_REJECTED, $done->refresh()->status);
        $this->assertSame(CsKnowledgeSuggestion::STATUS_PENDING, $other->refresh()->status);
        $this->assertSame($admin->id, CsIgnoredChat::query()->sole()->ignored_by);

        // Idempotent.
        $this->postJson("/api/cs-knowledge/suggestions/{$done->id}/ignore-chat")->assertOk();
        $this->assertSame(1, CsIgnoredChat::query()->count());
    }

    public function test_an_entry_can_be_created_edited_archived_and_restored(): void
    {
        $admin = $this->superAdmin();

        $id = $this->postJson('/api/cs-knowledge/entries', $this->entryPayload(['category' => 'payment']))->assertCreated()->json('id');
        $this->assertSame($admin->id, CsKnowledgeEntry::query()->find($id)->approved_by);

        $this->patchJson("/api/cs-knowledge/entries/{$id}", $this->entryPayload(['answer' => 'Jawapan baru.']))->assertOk();
        $this->assertSame('Jawapan baru.', CsKnowledgeEntry::query()->find($id)->answer);

        $this->patchJson("/api/cs-knowledge/entries/{$id}/archive")->assertOk();
        $this->getJson('/api/cs-knowledge/entries?status=approved')->assertJsonCount(0, 'data');
        $this->getJson('/api/cs-knowledge/entries?status=archived')->assertJsonCount(1, 'data');

        $this->patchJson("/api/cs-knowledge/entries/{$id}/restore")->assertOk();
        $this->assertSame(CsKnowledgeEntry::STATUS_APPROVED, CsKnowledgeEntry::query()->find($id)->status);
    }

    public function test_entry_input_is_validated(): void
    {
        $this->superAdmin();

        $this->postJson('/api/cs-knowledge/entries', $this->entryPayload(['category' => 'cost']))->assertJsonValidationErrors('category');
        $this->postJson('/api/cs-knowledge/entries', $this->entryPayload(['questions' => []]))->assertJsonValidationErrors('questions');
        $this->postJson('/api/cs-knowledge/entries', $this->entryPayload(['questions' => ['  ']]))->assertJsonValidationErrors('questions.0');
        $this->postJson('/api/cs-knowledge/entries', $this->entryPayload(['answer' => '']))->assertJsonValidationErrors('answer');
        $this->postJson('/api/cs-knowledge/entries', $this->entryPayload(['affiliate_id' => 999]))->assertJsonValidationErrors('affiliate_id');
        $this->postJson('/api/cs-knowledge/entries', $this->entryPayload(['valid_until' => '2020-01-01']))->assertJsonValidationErrors('valid_until');
    }
}
