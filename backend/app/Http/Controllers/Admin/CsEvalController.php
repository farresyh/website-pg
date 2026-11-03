<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CsKnowledge\CsEvalCaseRequest;
use App\Http\Requests\CsKnowledge\ImportCsEvalCasesRequest;
use App\Jobs\RunCsEvalCaseJob;
use App\Models\CsEvalCase;
use App\Models\CsEvalResult;
use App\Models\CsEvalRun;
use App\Models\CsKnowledgeEntry;
use App\Services\Llm\ChatModels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-136 decisions 12 and 28 (PR 3a): the model test, super_admin only.
 * Every configured model answers every active case; code judges safety; the
 * founder picks the most natural safe answer per case blind; model names,
 * cost and speed appear only after Reveal.
 */
class CsEvalController extends Controller
{
    private const CASE_FIELDS = ['title', 'kind', 'messages', 'affiliate_id', 'order_facts', 'expected_action', 'expected_reason', 'must_not_contain', 'notes', 'is_active'];

    public function cases(): JsonResponse
    {
        return response()->json(['data' => CsEvalCase::query()->orderByDesc('is_active')->orderBy('id')->get()]);
    }

    public function storeCase(CsEvalCaseRequest $request): JsonResponse
    {
        return response()->json(CsEvalCase::query()->create(Arr::only($request->validated(), self::CASE_FIELDS)), 201);
    }

    public function updateCase(CsEvalCaseRequest $request, CsEvalCase $case): JsonResponse
    {
        $case->update(Arr::only($request->validated(), self::CASE_FIELDS));

        return response()->json($case);
    }

    public function importCases(ImportCsEvalCasesRequest $request): JsonResponse
    {
        $created = DB::transaction(fn () => collect($request->validated()['cases'])
            ->map(fn (array $c) => CsEvalCase::query()->create(Arr::only($c, self::CASE_FIELDS)))
            ->count());

        return response()->json(['created' => $created], 201);
    }

    public function runs(): JsonResponse
    {
        $runs = CsEvalRun::query()
            ->withCount(['results', 'results as completed_count' => fn ($q) => $q->whereNotNull('completed_at')])
            ->latest('id')
            ->limit(20)
            ->get(['id', 'cases_count', 'knowledge_count', 'revealed_at', 'created_at']);

        return response()->json(['data' => $runs, 'configured' => app(ChatModels::class)->configured()]);
    }

    public function startRun(Request $request, ChatModels $models): JsonResponse
    {
        if (! $models->configured()) {
            throw ValidationException::withMessages(['models' => ['Not set on this server: '.implode(', ', $models->missingKeys()).'.']]);
        }

        $cases = CsEvalCase::query()->where('is_active', true)->orderBy('id')->pluck('id');
        if ($cases->isEmpty()) {
            throw ValidationException::withMessages(['cases' => ['Add at least one active test case first.']]);
        }

        $ids = $models->ids();
        $run = DB::transaction(function () use ($request, $cases, $ids) {
            $run = CsEvalRun::query()->create([
                'models' => $ids,
                'cases_count' => $cases->count(),
                'knowledge_count' => CsKnowledgeEntry::query()->where('status', CsKnowledgeEntry::STATUS_APPROVED)->count(),
                'started_by' => $request->user()->id,
            ]);

            foreach ($cases as $caseId) {
                // Blind: each case gets its own shuffle of labels, fixed for the run.
                $order = collect($ids)->sortBy(fn (string $m) => crc32("{$run->id}-{$caseId}-{$m}"))->values()->all();
                foreach ($order as $i => $model) {
                    CsEvalResult::query()->create([
                        'cs_eval_run_id' => $run->id,
                        'cs_eval_case_id' => $caseId,
                        'model' => $model,
                        'blind_label' => chr(65 + $i),
                    ]);
                }
            }

            return $run;
        });

        $run->results()->pluck('id')->each(fn (int $id) => RunCsEvalCaseJob::dispatch($id));

        return response()->json(['id' => $run->id], 201);
    }

    public function showRun(CsEvalRun $run): JsonResponse
    {
        $revealed = $run->revealed_at !== null;
        $results = $run->results()->with('evalCase')->orderBy('cs_eval_case_id')->orderBy('blind_label')->get();

        $cases = $results->groupBy('cs_eval_case_id')->map(function ($group) use ($revealed) {
            $case = $group->first()->evalCase;

            return [
                'case' => $case->only(['id', 'title', 'kind', 'messages', 'order_facts', 'expected_action', 'expected_reason']),
                'answers' => $group->map(fn (CsEvalResult $r) => [
                    'id' => $r->id,
                    'label' => $r->blind_label,
                    'done' => $r->completed_at !== null,
                    'action' => $r->action,
                    'reply' => $r->reply,
                    'handover_reason' => $r->handover_reason,
                    'safety_passed' => $r->safety_passed,
                    'safety_notes' => $r->safety_notes ?? [],
                    'picked' => $r->picked,
                ] + ($revealed ? ['model' => $r->model, 'cost_usd_micros' => $r->cost_usd_micros, 'latency_ms' => $r->latency_ms] : []))->values(),
            ];
        })->values();

        return response()->json([
            'id' => $run->id,
            'cases_count' => $run->cases_count,
            'knowledge_count' => $run->knowledge_count,
            'completed' => $results->whereNotNull('completed_at')->count(),
            'total' => $results->count(),
            'revealed_at' => $run->revealed_at,
            'cases' => $cases,
            'summary' => $revealed ? $this->summary($results) : null,
        ]);
    }

    public function pick(CsEvalRun $run, CsEvalResult $result): JsonResponse
    {
        if ($result->cs_eval_run_id !== $run->id) {
            abort(404);
        }
        if ($result->safety_passed !== true) {
            throw ValidationException::withMessages(['result' => ['Only an answer that passed the safety checks can be picked.']]);
        }

        DB::transaction(function () use ($run, $result) {
            CsEvalResult::query()->where('cs_eval_run_id', $run->id)->where('cs_eval_case_id', $result->cs_eval_case_id)->update(['picked' => false]);
            $result->update(['picked' => true]);
        });

        return response()->json(['picked' => $result->id]);
    }

    public function reveal(CsEvalRun $run): JsonResponse
    {
        $run->update(['revealed_at' => $run->revealed_at ?? now()]);

        return $this->showRun($run);
    }

    /** Decision 28: a model must pass every safety case; among those, picks decide, a near tie goes to the cheaper. */
    private function summary($results): array
    {
        return $results->groupBy('model')->map(fn ($rs, $model) => [
            'model' => $model,
            'answered' => $rs->whereNotNull('completed_at')->count(),
            'safety_failed' => $rs->filter(fn ($r) => $r->evalCase->kind === 'safety' && $r->safety_passed === false)->count(),
            'checks_failed' => $rs->where('safety_passed', false)->count(),
            'picked' => $rs->where('picked', true)->count(),
            'cost_usd_micros' => (int) $rs->sum('cost_usd_micros'),
            'avg_latency_ms' => (int) round($rs->avg('latency_ms') ?? 0),
        ])->values()->all();
    }
}
