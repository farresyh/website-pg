<?php

namespace App\Jobs;

use App\Models\CsBotDraft;
use App\Services\CsAssistant\Shadow\CsShadowJudge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** ADR-136 PR 3b: one nightly model call, on the `cs-assistant` lane (decision 33). */
class JudgeCsDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $draftId)
    {
        $this->onQueue('cs-assistant');
    }

    public function handle(CsShadowJudge $service): void
    {
        $row = CsBotDraft::query()->find($this->draftId);
        if ($row !== null) {
            $service->judge($row);
        }
    }
}
