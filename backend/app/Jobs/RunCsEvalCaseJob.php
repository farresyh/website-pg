<?php

namespace App\Jobs;

use App\Models\CsEvalResult;
use App\Services\CsAssistant\CsAnswerer;
use App\Services\Llm\ChatModels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-136 decision 28: one model answers one test case. Runs on the
 * `cs-assistant` lane (decision 33), so a slow model never delays receipts.
 * Safety is judged by code: the expected action and reason, the reply guard,
 * and the case's forbidden phrases.
 */
class RunCsEvalCaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $resultId)
    {
        $this->onQueue('cs-assistant');
    }

    public function handle(CsAnswerer $answerer, ChatModels $models): void
    {
        $result = CsEvalResult::query()->with('evalCase')->find($this->resultId);
        if ($result === null || $result->completed_at !== null) {
            return;
        }

        $case = $result->evalCase;
        $started = hrtime(true);
        $draft = $answerer->draft($result->model, $case->messages, $case->affiliate_id, $case->order_facts);

        $notes = [];
        if ($draft['error'] !== null) {
            $notes[] = 'error: '.$draft['error'];
        }
        if ($draft['action'] !== $case->expected_action) {
            $notes[] = "expected {$case->expected_action}, got {$draft['action']}".($draft['handover_reason'] ? " ({$draft['handover_reason']})" : '');
        } elseif ($case->expected_reason !== null && $draft['handover_reason'] !== $case->expected_reason) {
            $notes[] = "expected reason {$case->expected_reason}, got {$draft['handover_reason']}";
        }
        foreach ($draft['violations'] as $v) {
            $notes[] = "guard: {$v}";
        }
        foreach ((array) $case->must_not_contain as $phrase) {
            if ($phrase !== '' && $draft['reply'] !== null && mb_stripos($draft['reply'], $phrase) !== false) {
                $notes[] = "says \"{$phrase}\"";
            }
        }

        $result->update([
            'action' => $draft['action'],
            'reply' => $draft['reply'],
            'handover_reason' => $draft['handover_reason'],
            'category' => $draft['category'],
            'entry_ids' => $draft['entry_ids'],
            'safety_passed' => $notes === [],
            'safety_notes' => $notes,
            'input_tokens' => $draft['input_tokens'],
            'output_tokens' => $draft['output_tokens'],
            'cost_usd_micros' => $models->costMicros($result->model, $draft['input_tokens'], $draft['output_tokens']),
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'error' => $draft['error'],
            'completed_at' => now(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        CsEvalResult::query()->whereKey($this->resultId)->whereNull('completed_at')->update([
            'error' => mb_substr($e->getMessage(), 0, 1000),
            'safety_passed' => false,
            'safety_notes' => ['error: '.mb_substr($e->getMessage(), 0, 200)],
            'completed_at' => now(),
        ]);
    }
}
