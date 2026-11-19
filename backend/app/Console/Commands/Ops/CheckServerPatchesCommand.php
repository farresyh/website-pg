<?php

namespace App\Console\Commands\Ops;

use App\Services\Backup\BackupFailureAlerter;
use App\Services\Ops\ServerPatchCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-145 decision 3: emails every admin when a problem appears, and again
 * every REMIND_DAYS while it stays open (a pending reboot sat unnoticed for
 * weeks before this). A resolved problem drops out of the state, so its
 * return is emailed at once. The state is a cache key: losing it costs one
 * repeated email, nothing more.
 */
#[Signature('app:check-server-patches')]
#[Description('Email admins when production runs an outdated PHP or waits for a reboot.')]
class CheckServerPatchesCommand extends Command
{
    public const REMIND_DAYS = 7;

    private const STATE_KEY = 'server-patch-alerts';

    public function handle(ServerPatchCheck $check, BackupFailureAlerter $alerter): int
    {
        /** @var array<string, int> $sent problem key => unix time last emailed */
        $sent = Cache::get(self::STATE_KEY, []);
        $state = [];

        foreach ($check->problems() as $problem) {
            $last = $sent[$problem['key']] ?? null;

            if ($last === null || $last <= now()->subDays(self::REMIND_DAYS)->getTimestamp()) {
                $alerter->alert($problem['subject'], $problem['message'], 'Server');
                $last = now()->getTimestamp();
            }

            $state[$problem['key']] = $last;
        }

        Cache::forever(self::STATE_KEY, $state);

        return self::SUCCESS;
    }
}
