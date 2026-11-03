<?php

namespace App\Console\Commands\CsAssistant;

use App\Jobs\JudgeCsDraftJob;
use App\Services\CsAssistant\Shadow\CsShadowJudge;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-136 decision 18: nightly, each Shadow draft whose answer window has
 * closed is paired with staff's reply and graded, one job per draft on the
 * cs-assistant lane. Re-runnable: a graded draft is skipped.
 */
#[Signature('cs:judge-drafts')]
#[Description('Grade Shadow drafts against what CS staff actually replied.')]
class JudgeCsDraftsCommand extends Command
{
    public function handle(CsShadowJudge $judge): int
    {
        $due = $judge->due(CarbonImmutable::now());
        $due->each(fn ($draft) => JudgeCsDraftJob::dispatch($draft->id));

        $this->info("Queued {$due->count()} Shadow draft(s) for judging.");
        Log::info('CS Shadow drafts queued for judging', ['drafts' => $due->count()]);

        return self::SUCCESS;
    }
}
