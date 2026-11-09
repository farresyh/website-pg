<?php

namespace App\Console\Commands\Reseller;

use App\Services\Rebate\RebateNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** ADR-139 decisions 33, 40, 47: see RebateNotifier. Every 10 minutes; each message is sent once by its dedupe key. */
#[Signature('app:notify-rebate-progress')]
#[Description('Tell resellers on WhatsApp about newly reached rebate steps and rewards about to lapse.')]
class NotifyRebateProgressCommand extends Command
{
    public function handle(RebateNotifier $notifier): int
    {
        $notifier->notifyProgress();

        return self::SUCCESS;
    }
}
