<?php

namespace App\Console\Commands\Membership;

use App\Models\Membership;
use App\Services\Membership\MembershipStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-027 base decision 7 / Phase 6: the rolling-30-day quota refill —
 * without this, a member's `quota_remaining_sen` permanently exhausts
 * ~30 days after subscribing and never recovers. Runs on a schedule
 * (see routes/console.php), same inert-until-real-cron pattern as
 * SyncSupplierPricesJob/ReconcilePendingPaymentsCommand.
 *
 * ADR-027 continued addendum decision 15 / Phase 6.5 (grilled
 * 2026-08-29, Q7): this command also performs the active -> expired
 * flip the membership state machine had always intended but never
 * actually wrote — a member past `expires_at` is flipped to `expired`
 * BEFORE the quota-refill sweep runs, so a lapsed member's quota is
 * never pointlessly refilled (their checkout is already blocked on
 * `expires_at`, and now their registry status reads `expired` too).
 *
 * Each eligible row is locked individually (`lockForUpdate()` inside its
 * own `DB::transaction()`), same discipline as
 * MembershipQuotaService::decrement() — a reset writing an absolute
 * `quota_remaining_sen` value could otherwise stomp a concurrent
 * in-flight checkout decrement racing the exact same row.
 */
#[Signature('app:reset-membership-cycles')]
#[Description('Flip expired memberships to Expired, then refill quota_remaining_sen and advance cycle_started_at for every active membership whose 30-day cycle has elapsed.')]
class ResetMembershipCyclesCommand extends Command
{
    private const CYCLE_DAYS = 30;

    public function handle(): int
    {
        $expired = Membership::query()
            ->where('status', MembershipStatus::Active)
            ->where('expires_at', '<', now())
            ->update(['status' => MembershipStatus::Expired]);

        $eligibleIds = Membership::query()
            ->where('status', MembershipStatus::Active)
            ->where('cycle_started_at', '<=', now()->subDays(self::CYCLE_DAYS))
            ->pluck('id');

        $reset = 0;

        foreach ($eligibleIds as $id) {
            DB::transaction(function () use ($id, &$reset) {
                $membership = Membership::query()->with('membershipPlan')->lockForUpdate()->find($id);

                if ($membership === null || $membership->membershipPlan === null) {
                    return;
                }

                $membership->update([
                    'quota_remaining_sen' => $membership->membershipPlan->quota_sen,
                    'cycle_started_at' => now(),
                ]);

                $reset++;
            });
        }

        $this->info("Expired {$expired} membership(s); reset {$reset} membership cycle(s).");
        Log::info('Reset membership cycles', ['expired' => $expired, 'count' => $reset]);

        return self::SUCCESS;
    }
}
