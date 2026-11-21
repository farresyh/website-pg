<?php

namespace App\Console\Commands\CsAssistant;

use App\Models\CsBotDraft;
use App\Models\CsMessage;
use App\Services\CsAssistant\CsSenderClassifier;
use App\Services\CsAssistant\Shadow\CsShadowJudge;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One-off repair for the 2026-11-21 classifier fix: outgoing messages kept
 * as staff that are our own sends (matched by message id, the classifier's
 * own rule) become system. Shadow drafts whose answer window held one of
 * them are ungraded, so the nightly judge grades them again against what
 * staff really wrote. Idempotent: a second run finds nothing.
 */
#[Signature('cs:reclassify-outgoing {--dry-run : Report what would change, change nothing}')]
#[Description('Re-run sender classification on outgoing CS messages stored as staff, and re-queue the Shadow grades they skewed.')]
class ReclassifyCsOutgoingCommand extends Command
{
    public function handle(CsSenderClassifier $classifier): int
    {
        $dry = (bool) $this->option('dry-run');

        /** @var Collection<int, CsMessage> $ours */
        $ours = collect();
        CsMessage::query()
            ->where('direction', 'out')
            ->where('sender', CsMessage::SENDER_STAFF)
            ->whereNotNull('wa_message_id')
            ->lazyById()
            ->each(function (CsMessage $m) use ($classifier, $dry, $ours) {
                $verdict = $classifier->classify($m->wa_message_id, null, $m->sent_at);
                if ($verdict['sender'] !== CsMessage::SENDER_SYSTEM) {
                    return;
                }

                if (! $dry) {
                    $m->update(['sender' => CsMessage::SENDER_SYSTEM, 'customer_notification_id' => $verdict['notification_id']]);
                }
                $ours->push($m);
            });

        $drafts = $ours->isEmpty() ? CsBotDraft::query()->whereRaw('1 = 0') : CsBotDraft::query()
            ->whereNotNull('judged_at')
            ->where(function (Builder $q) use ($ours) {
                foreach ($ours as $m) {
                    // A draft is graded against staff's reply within the answer window after it.
                    $q->orWhere(fn (Builder $w) => $w->where('chat_hash', $m->chat_hash)
                        ->whereBetween('asked_at', [$m->sent_at->copy()->subMinutes(CsShadowJudge::ANSWER_WINDOW_MINUTES), $m->sent_at]));
                }
            });

        $regraded = $dry ? $drafts->count() : $drafts->update([
            'staff_answer' => null, 'grade' => null, 'grade_note' => null,
            'judge_model' => null, 'judge_cost_usd_micros' => 0, 'judged_at' => null,
        ]);

        $this->info(sprintf('%s%d outgoing message(s) are our own sends; %d Shadow draft(s) to grade again.', $dry ? '[dry run] ' : '', $ours->count(), $regraded));

        return self::SUCCESS;
    }
}
