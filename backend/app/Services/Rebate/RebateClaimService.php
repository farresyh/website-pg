<?php

namespace App\Services\Rebate;

use App\Models\AffiliateUser;
use App\Models\LedgerAccount;
use App\Models\Reseller;
use App\Models\ResellerRebateMilestone;
use App\Models\ResellerRebateMonth;
use App\Models\ResellerRebateTermsAcceptance;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\WalletEntryType;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-139 decisions 4, 9–13, 16, 34, 36: the only writer of `wallet_rebate`.
 *
 * One transaction under the reseller's wallet mutex (`ledger_accounts`, the
 * row `assertWithinLimits()` and `debit()` lock, and the month finalise will
 * take too). Steps are claimed in order. A claim of step k pays
 *
 *   min(ladder cumulative at k, cap) − already paid this month
 *
 * so a cut on an early claim is paid back by the next one (stress test F1).
 * The request carries only the month and the step; every amount is computed
 * here. Portal only (decision 16): the caller is a portal user.
 */
final class RebateClaimService
{
    public function __construct(
        private readonly RebateEntitlement $entitlement,
        private readonly LedgerService $ledger,
    ) {}

    public function claim(Reseller $reseller, AffiliateUser $user, int $year, int $month, int $step, bool $acceptTerms = false): ResellerRebateMilestone
    {
        return DB::transaction(function () use ($reseller, $user, $year, $month, $step, $acceptTerms) {
            LedgerAccount::query()
                ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
                ->where('owner_id', $reseller->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fresh = Reseller::query()->findOrFail($reseller->id);
            $this->refuseUnless($user->owner_type === AccountOwnerType::Reseller && (int) $user->owner_id === $fresh->id && $user->is_active, 'This login cannot claim for that account.');
            $this->refuseUnless($fresh->is_active, 'This account is deactivated, so it cannot claim a rebate.');
            $this->refuseUnless($fresh->rebate_eligible, 'This account is not part of the rebate programme.');
            $this->acceptTerms($fresh, $user, $acceptTerms);

            $period = CarbonImmutable::create($year, $month, 1, 0, 0, 0, MalaysiaTime::TIMEZONE);
            $now = CarbonImmutable::now(MalaysiaTime::TIMEZONE);
            $this->refuseUnless($period->lessThanOrEqualTo($now), 'That month has not started.');
            $this->refuseUnless($now->lessThan($period->addMonthsNoOverflow(2)), 'The claim window for that month has closed.');

            $rows = ResellerRebateMilestone::query()
                ->where('reseller_id', $fresh->id)
                ->whereDate('period', $period->toDateString())
                ->lockForUpdate()
                ->get();

            // Step 0 = the month's adjustment row, written only by the month finalise (decision 12).
            if ($step === 0) {
                $row = $rows->firstWhere('kind', ResellerRebateMilestone::KIND_ADJUSTMENT);
                $this->refuseUnless($row?->state === ResellerRebateMilestone::AVAILABLE, 'There is no adjustment to claim for that month.');

                return $this->pay($row, $user);
            }

            $claimed = $rows->where('kind', ResellerRebateMilestone::KIND_STEP)->keyBy('step');
            $this->refuseUnless(($claimed[$step]->state ?? ResellerRebateMilestone::AVAILABLE) === ResellerRebateMilestone::AVAILABLE, 'That reward was already claimed.');
            for ($earlier = 1; $earlier < $step; $earlier++) {
                $this->refuseUnless(($claimed[$earlier]->state ?? null) === ResellerRebateMilestone::CLAIMED, 'Claim the earlier rewards first.');
            }

            // A finalised month's row carries its amount, allocated from the final entitlement: pay exactly that.
            if (isset($claimed[$step])) {
                return $this->pay($claimed[$step], $user);
            }

            // A finalised month is fixed (its month may be closed): only the rows finalise wrote can be claimed.
            $this->refuseUnless(! ResellerRebateMonth::query()->whereDate('period', $period->toDateString())->exists(), 'That reward was not reached by the end of that month.');

            $standing = $this->entitlement->standing($fresh, $year, $month, now());
            $this->refuseUnless($standing->earnsRebate(), 'There is no rebate for this account in that month.');
            $this->refuseUnless($step >= 1 && $step <= $standing->reachedStep, 'That reward has not been reached yet.');

            $pay = max(0, $standing->entitlementThrough($step) - $standing->paidSen);
            $values = [
                'ladder_id' => $standing->ladder->id,
                'tier_id' => $standing->tierId,
                'amount_sen' => $pay,
                'adjusted' => $pay < $standing->ladderCumulative($step) - $standing->ladderCumulative($step - 1),
                'state' => ResellerRebateMilestone::CLAIMED,
                'sales_sen' => $standing->salesSen,
                'profit_sen' => $standing->profitSen,
                'ladder_cumulative_sen' => $standing->ladderCumulative($step),
                'cap_sen' => $standing->capSen,
                'computed_at' => now(),
                'claimed_at' => now(),
                'claimed_by' => $user->id,
            ];

            $row = ResellerRebateMilestone::query()->create([
                'reseller_id' => $fresh->id,
                'period' => $period->toDateString(),
                'kind' => ResellerRebateMilestone::KIND_STEP,
                'step' => $step,
                ...$values,
            ]);
            $this->credit($row);

            return $row;
        });
    }

    /** Claims a row the month finalise already wrote, at its stored amount. */
    private function pay(ResellerRebateMilestone $row, AffiliateUser $user): ResellerRebateMilestone
    {
        $row->update(['state' => ResellerRebateMilestone::CLAIMED, 'claimed_at' => now(), 'claimed_by' => $user->id]);
        $this->credit($row);

        return $row;
    }

    private function credit(ResellerRebateMilestone $row): void
    {
        if ($row->amount_sen > 0) {
            $this->ledger->credit(LedgerOwnerType::ResellerWallet, $row->reseller_id, $row->amount_sen, WalletEntryType::Rebate->value, 'rebate_milestone', $row->id);
        }
    }

    private function acceptTerms(Reseller $reseller, AffiliateUser $user, bool $accept): void
    {
        $accepted = ResellerRebateTermsAcceptance::query()
            ->where('reseller_id', $reseller->id)
            ->where('terms_version', RebateTerms::VERSION)
            ->exists();

        if ($accepted) {
            return;
        }

        $this->refuseUnless($accept, 'Accept the rebate programme terms to claim.', 'terms');
        ResellerRebateTermsAcceptance::query()->create([
            'reseller_id' => $reseller->id,
            'terms_version' => RebateTerms::VERSION,
            'affiliate_user_id' => $user->id,
            'accepted_at' => now(),
        ]);
    }

    private function refuseUnless(bool $ok, string $message, string $field = 'rebate'): void
    {
        if (! $ok) {
            throw ValidationException::withMessages([$field => [$message]]);
        }
    }
}
