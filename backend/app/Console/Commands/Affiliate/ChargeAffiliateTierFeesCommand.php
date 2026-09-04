<?php

namespace App\Console\Commands\Affiliate;

use App\Models\AffiliateSubscription;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use App\Services\Affiliate\AffiliateTierFeeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-056 (grilled 2026-08-30) decision 6: collects the monthly affiliate
 * wholesale-tier fee from each due subscription's earnings balance and
 * drives the active → grace → lapsed transitions. Runs on a schedule
 * (routes/console.php) — same inert-until-a-real-OS-cron pattern as
 * ResetMembershipCyclesCommand / ReconcilePendingPaymentsCommand; a no-op
 * locally today, activates for free once a deployed host runs
 * `schedule:run`. An admin can also invoke it by hand.
 *
 * "Due" = an `active` subscription past its `next_charge_at`, OR any
 * subscription already in `grace` (retried each run until it either pays
 * or `grace_until` elapses and it lapses). `lapsed` subscriptions are
 * skipped — reactivation is an admin action (ADR-058), not an auto-retry.
 */
#[Signature('app:charge-affiliate-tier-fees')]
#[Description('Collect the monthly affiliate wholesale-tier fee from earnings for every due subscription; transition active -> grace -> lapsed on an unpaid cycle.')]
class ChargeAffiliateTierFeesCommand extends Command
{
    public function handle(AffiliateTierFeeService $service): int
    {
        $dueIds = AffiliateSubscription::query()
            ->where(function ($query) {
                $query->where('status', AffiliateSubscriptionStatus::Active)
                    ->where('next_charge_at', '<=', now());
            })
            ->orWhere('status', AffiliateSubscriptionStatus::Grace)
            ->pluck('id');

        $charged = 0;
        $graced = 0;
        $lapsed = 0;

        foreach ($dueIds as $id) {
            $subscription = AffiliateSubscription::query()->find($id);

            if ($subscription === null) {
                continue;
            }

            $before = $subscription->status;
            $after = $service->chargeCycle($subscription)->status;

            match (true) {
                $before !== AffiliateSubscriptionStatus::Lapsed && $after === AffiliateSubscriptionStatus::Lapsed => $lapsed++,
                $before !== AffiliateSubscriptionStatus::Grace && $after === AffiliateSubscriptionStatus::Grace => $graced++,
                $after === AffiliateSubscriptionStatus::Active => $charged++,
                default => null,
            };
        }

        $this->info("Affiliate tier fees: {$charged} charged, {$graced} entered grace, {$lapsed} lapsed.");
        Log::info('Charged affiliate tier fees', compact('charged', 'graced', 'lapsed'));

        return self::SUCCESS;
    }
}
