<?php

namespace App\Console\Commands\Reseller;

use App\Models\ResellerSubscription;
use App\Services\Reseller\ResellerSubscriptionStatus;
use App\Services\Reseller\ResellerTierFeeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-056 (grilled 2026-08-30) decision 6: collects the monthly reseller
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
#[Signature('app:charge-reseller-tier-fees')]
#[Description('Collect the monthly reseller wholesale-tier fee from earnings for every due subscription; transition active -> grace -> lapsed on an unpaid cycle.')]
class ChargeResellerTierFeesCommand extends Command
{
    public function handle(ResellerTierFeeService $service): int
    {
        $dueIds = ResellerSubscription::query()
            ->where(function ($query) {
                $query->where('status', ResellerSubscriptionStatus::Active)
                    ->where('next_charge_at', '<=', now());
            })
            ->orWhere('status', ResellerSubscriptionStatus::Grace)
            ->pluck('id');

        $charged = 0;
        $graced = 0;
        $lapsed = 0;

        foreach ($dueIds as $id) {
            $subscription = ResellerSubscription::query()->find($id);

            if ($subscription === null) {
                continue;
            }

            $before = $subscription->status;
            $after = $service->chargeCycle($subscription)->status;

            match (true) {
                $before !== ResellerSubscriptionStatus::Lapsed && $after === ResellerSubscriptionStatus::Lapsed => $lapsed++,
                $before !== ResellerSubscriptionStatus::Grace && $after === ResellerSubscriptionStatus::Grace => $graced++,
                $after === ResellerSubscriptionStatus::Active => $charged++,
                default => null,
            };
        }

        $this->info("Reseller tier fees: {$charged} charged, {$graced} entered grace, {$lapsed} lapsed.");
        Log::info('Charged reseller tier fees', compact('charged', 'graced', 'lapsed'));

        return self::SUCCESS;
    }
}
