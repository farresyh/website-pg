<?php

namespace App\Jobs;

use App\Services\CsAssistant\Shadow\CsShadowDrafter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-136 decision 22: drafts the Shadow answer to a customer burst once the
 * customer has been quiet for the panel's wait, on the `cs-assistant` lane.
 */
class DraftCsReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $messageId)
    {
        $this->onQueue('cs-assistant');
    }

    public function handle(CsShadowDrafter $drafter): void
    {
        $drafter->draft($this->messageId);
    }
}
