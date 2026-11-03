<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CsKnowledge\CsKnowledgeEntryRequest;
use App\Http\Requests\CsKnowledge\MergeCsKnowledgeSuggestionRequest;
use App\Models\CsIgnoredChat;
use App\Models\CsKnowledgeEntry;
use App\Models\CsKnowledgeSuggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ADR-136 decisions 2, 10, 16, 17 (PR 2): the admin "CS knowledge" page,
 * super_admin only. Nothing becomes knowledge without a person approving it.
 */
class CsKnowledgeController extends Controller
{
    private const RELATIONS = ['affiliate:id,business_name', 'game:id,name', 'approver:id,name'];

    public function suggestions(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(CsKnowledgeSuggestion::STATUSES)]]);

        $page = CsKnowledgeSuggestion::query()
            ->with(['affiliate:id,business_name', 'entry:id,questions', 'reviewer:id,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('asked_at')
            ->paginate(25);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => CsKnowledgeSuggestion::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function approve(CsKnowledgeEntryRequest $request, CsKnowledgeSuggestion $suggestion): JsonResponse
    {
        $entry = $this->review($suggestion, $request->user()->id, CsKnowledgeSuggestion::STATUS_APPROVED, function () use ($request) {
            return CsKnowledgeEntry::query()->create($this->entryData($request) + ['status' => CsKnowledgeEntry::STATUS_APPROVED]);
        });

        return response()->json($entry->load(self::RELATIONS), 201);
    }

    public function merge(MergeCsKnowledgeSuggestionRequest $request, CsKnowledgeSuggestion $suggestion): JsonResponse
    {
        $data = $request->validated();

        $entry = $this->review($suggestion, $request->user()->id, CsKnowledgeSuggestion::STATUS_MERGED, function () use ($data, $request) {
            $entry = CsKnowledgeEntry::query()->lockForUpdate()->findOrFail($data['cs_knowledge_entry_id']);

            if ($entry->status !== CsKnowledgeEntry::STATUS_APPROVED) {
                throw ValidationException::withMessages(['cs_knowledge_entry_id' => ['Restore this entry before merging into it.']]);
            }

            $entry->update([
                'questions' => [...$entry->questions, trim($data['question'])],
                'answer' => filled($data['answer'] ?? null) ? $data['answer'] : $entry->answer,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return $entry;
        });

        return response()->json($entry->load(self::RELATIONS));
    }

    public function reject(Request $request, CsKnowledgeSuggestion $suggestion): JsonResponse
    {
        $this->review($suggestion, $request->user()->id, CsKnowledgeSuggestion::STATUS_REJECTED, fn () => null);

        return response()->json($suggestion->refresh());
    }

    /** Decision 16: the founder's and staff's own test chats. Idempotent. */
    public function ignoreChat(Request $request, CsKnowledgeSuggestion $suggestion): JsonResponse
    {
        $closed = DB::transaction(function () use ($request, $suggestion) {
            CsIgnoredChat::query()->firstOrCreate(['chat_hash' => $suggestion->chat_hash], ['ignored_by' => $request->user()->id]);

            return CsKnowledgeSuggestion::query()
                ->where('chat_hash', $suggestion->chat_hash)
                ->where('status', CsKnowledgeSuggestion::STATUS_PENDING)
                ->update(['status' => CsKnowledgeSuggestion::STATUS_IGNORED, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        });

        return response()->json(['closed' => $closed]);
    }

    public function entries(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in([CsKnowledgeEntry::STATUS_APPROVED, CsKnowledgeEntry::STATUS_ARCHIVED])],
            // The merge picker loads every approved entry at once.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $page = CsKnowledgeEntry::query()
            ->with(self::RELATIONS)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('updated_at')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => CsKnowledgeEntry::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function store(CsKnowledgeEntryRequest $request): JsonResponse
    {
        $entry = CsKnowledgeEntry::query()->create($this->entryData($request) + ['status' => CsKnowledgeEntry::STATUS_APPROVED]);

        return response()->json($entry->load(self::RELATIONS), 201);
    }

    public function update(CsKnowledgeEntryRequest $request, CsKnowledgeEntry $entry): JsonResponse
    {
        $entry->update($this->entryData($request));

        return response()->json($entry->load(self::RELATIONS));
    }

    public function archive(CsKnowledgeEntry $entry): JsonResponse
    {
        $entry->update(['status' => CsKnowledgeEntry::STATUS_ARCHIVED]);

        return response()->json($entry->load(self::RELATIONS));
    }

    public function restore(CsKnowledgeEntry $entry): JsonResponse
    {
        $entry->update(['status' => CsKnowledgeEntry::STATUS_APPROVED]);

        return response()->json($entry->load(self::RELATIONS));
    }

    /** An edit is a fresh approval by whoever made it. */
    private function entryData(CsKnowledgeEntryRequest $request): array
    {
        $data = $request->validated();
        $data['questions'] = array_values(array_map('trim', $data['questions']));

        return $data + ['approved_by' => $request->user()->id, 'approved_at' => now()];
    }

    /**
     * Moves one pending suggestion to its reviewed state; refuses one already
     * reviewed (two admins on the same row).
     *
     * @template T
     *
     * @param  callable(): T  $apply
     * @return T
     */
    private function review(CsKnowledgeSuggestion $suggestion, int $adminId, string $status, callable $apply): mixed
    {
        return DB::transaction(function () use ($suggestion, $adminId, $status, $apply) {
            $locked = CsKnowledgeSuggestion::query()->lockForUpdate()->findOrFail($suggestion->id);

            if ($locked->status !== CsKnowledgeSuggestion::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => ['This suggestion was already reviewed.']]);
            }

            $result = $apply();

            $locked->update([
                'status' => $status,
                'cs_knowledge_entry_id' => $result instanceof CsKnowledgeEntry ? $result->id : null,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);

            return $result;
        });
    }
}
