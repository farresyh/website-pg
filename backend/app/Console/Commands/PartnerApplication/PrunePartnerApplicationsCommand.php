<?php

namespace App\Console\Commands\PartnerApplication;

use App\Models\PartnerApplication;
use App\Services\PartnerApplication\PartnerApplicationService;
use App\Services\PartnerApplication\PartnerApplicationStatus as Status;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-133 decision 9 and its 2026-10-31 addendum: the retention the PDPA notices promise.
 * - a declined application, 90 days after the decision;
 * - an approved one, 90 days after its reseller account was closed (closing soft-deletes it);
 * - an application whose email was never confirmed, 7 days after it was last sent (2026-11-01
 *   addendum: it holds a stranger's answers and never reached the admin).
 * Files first, then the row, through the same service as the admin's delete. Idempotent: a second
 * run finds nothing.
 */
#[Signature('app:prune-partner-applications')]
#[Description('Delete reseller applications and their KYC files past their retention period.')]
class PrunePartnerApplicationsCommand extends Command
{
    public const RETENTION_DAYS = 90;

    public const UNCONFIRMED_DAYS = 7;

    public function handle(PartnerApplicationService $applications): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        $due = PartnerApplication::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('status', Status::Declined)->where('decided_at', '<=', $cutoff))
                ->orWhere(fn ($q) => $q->where('status', Status::Approved)
                    ->whereHas('reseller', fn ($r) => $r->onlyTrashed()->where('deleted_at', '<=', $cutoff)))
                ->orWhere(fn ($q) => $q->where('status', Status::EmailPending)->where('updated_at', '<=', now()->subDays(self::UNCONFIRMED_DAYS))))
            ->get();

        $due->each(fn (PartnerApplication $application) => $applications->deleteData($application));

        $this->info("Deleted {$due->count()} partner application(s).");
        Log::info('Pruned partner applications', ['deleted' => $due->count(), 'ids' => $due->pluck('id')->all()]);

        return self::SUCCESS;
    }
}
