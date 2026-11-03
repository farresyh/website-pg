<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CsKnowledge\GradeCsBotDraftRequest;
use App\Http\Requests\CsKnowledge\UpdateCsAssistantSettingsRequest;
use App\Models\CsAssistantSettings;
use App\Models\CsBotDraft;
use App\Models\CsKnowledgeEntry;
use App\Services\CsAssistant\Shadow\CsSpend;
use App\Services\Llm\ChatModels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ADR-136 PR 3b, super_admin only: the assistant's settings (decision 33),
 * the Shadow drafts beside staff's replies with their grades (decision 18),
 * and per-category accuracy with decision 27's "ready for Live" mark.
 */
class CsShadowController extends Controller
{
    public function settings(ChatModels $models, CsSpend $spend): JsonResponse
    {
        $settings = CsAssistantSettings::current();

        return response()->json([
            'data' => $settings->only([
                'mode', 'answer_model', 'judge_model', 'reassure_minutes', 'handover_minutes', 'manual_handover_minutes',
                'burst_wait_seconds', 'daily_cost_cap_sen', 'live_min_drafts', 'live_min_accuracy_pct', 'cost_cap_tripped_at', 'updated_at',
            ]),
            'models' => array_keys((array) config('services.cs_assistant.models')),
            'missing_keys' => $models->missingKeys([$settings->answer_model, $settings->judge_model]),
            'spent_today_sen' => $spend->todaySen(),
        ]);
    }

    public function updateSettings(UpdateCsAssistantSettingsRequest $request, ChatModels $models, CsSpend $spend): JsonResponse
    {
        $settings = CsAssistantSettings::current();
        $data = $request->validated();

        // Turning Shadow on (again) clears a tripped cap; the cap still applies to today's spend.
        if ($data['mode'] === CsAssistantSettings::MODE_SHADOW && $settings->mode !== CsAssistantSettings::MODE_SHADOW) {
            $data['cost_cap_tripped_at'] = null;
        }

        $settings->update($data + ['updated_by' => $request->user()->id]);

        return $this->settings($models, $spend);
    }

    public function drafts(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['nullable', Rule::in(CsKnowledgeEntry::CATEGORIES)],
            'grade' => ['nullable', Rule::in([...CsBotDraft::GRADES, 'ungraded', 'no_staff_answer'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = CsBotDraft::query()
            ->with(['affiliate:id,business_name'])
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('grade'), fn ($q, $g) => match ($g) {
                'ungraded' => $q->whereNull('judged_at'),
                'no_staff_answer' => $q->whereNotNull('judged_at')->whereNull('grade')->whereNull('admin_grade'),
                default => $q->where(fn ($q) => $q->where('admin_grade', $g)->orWhere(fn ($q) => $q->whereNull('admin_grade')->where('grade', $g))),
            })
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (CsBotDraft $d) => $d->only([
                'id', 'question', 'order_numbers', 'asked_at', 'model', 'action', 'reply', 'handover_reason', 'category', 'entry_ids',
                'violations', 'error', 'staff_answer', 'grade', 'grade_note', 'judged_at', 'admin_grade',
            ]) + ['brand' => $d->affiliate?->business_name, 'final_grade' => $d->finalGrade()]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function grade(GradeCsBotDraftRequest $request, CsBotDraft $draft): JsonResponse
    {
        $draft->update(['admin_grade' => $request->validated('grade'), 'graded_by' => $request->user()->id]);

        return response()->json(['id' => $draft->id, 'admin_grade' => $draft->admin_grade, 'final_grade' => $draft->finalGrade()]);
    }

    /** Decision 27: per category, how many graded drafts and how many right; ready only marks, never switches. */
    public function accuracy(): JsonResponse
    {
        $settings = CsAssistantSettings::current();
        $graded = CsBotDraft::query()
            ->whereNotNull('category')
            ->where(fn ($q) => $q->whereNotNull('admin_grade')->orWhere(fn ($q) => $q->whereNotNull('grade')->where('grade', '!=', 'unclear')))
            ->get(['category', 'grade', 'admin_grade', 'violations'])
            ->reject(fn (CsBotDraft $d) => $d->finalGrade() === 'unclear');

        $rows = collect(CsKnowledgeEntry::CATEGORIES)->map(function (string $category) use ($graded, $settings) {
            $drafts = $graded->where('category', $category);
            $total = $drafts->count();
            $right = $drafts->filter(fn (CsBotDraft $d) => in_array($d->finalGrade(), CsBotDraft::RIGHT, true))->count();
            $shouldHave = $drafts->filter(fn (CsBotDraft $d) => $d->finalGrade() === 'should_have_handed_over')->count();
            $guardHits = $drafts->filter(fn (CsBotDraft $d) => ! empty($d->violations))->count();
            $pct = $total === 0 ? null : (int) floor($right * 100 / $total);

            return [
                'category' => $category,
                'graded' => $total,
                'right' => $right,
                'accuracy_pct' => $pct,
                'should_have_handed_over' => $shouldHave,
                'guard_hits' => $guardHits,
                'ready' => $total >= $settings->live_min_drafts && $pct >= $settings->live_min_accuracy_pct && $shouldHave === 0 && $guardHits === 0,
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }
}
