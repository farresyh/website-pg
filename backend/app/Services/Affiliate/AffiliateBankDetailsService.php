<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateBankChange;
use App\Models\AffiliateImpersonationSession;
use App\Models\AffiliateUser;
use App\Services\Auth\AccountOwnerType;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Membership\PlunkMailer;
use App\Services\Membership\PlunkSendException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-058 2026-11-16 addendum: the one writer of an affiliate's payout bank
 * details (the fields are not fillable on `Affiliate`). A monthly payout
 * ticket copies them when it opens (`AffiliateWithdrawalService`), so a
 * change is money: each one is recorded in `affiliate_bank_changes`, and the
 * affiliate and every admin are emailed after the commit. A mail failure is
 * logged and never undoes the change.
 *
 * The admin Withdrawals page already flags a ticket whose details differ
 * from the last approved payout (`bank_details_changed_since_last_approval`).
 */
final class AffiliateBankDetailsService
{
    public const FIELDS = ['bank_name', 'bank_account_no', 'bank_account_holder'];

    public function __construct(
        private readonly PlunkMailer $mailer,
        private readonly BackupFailureAlerter $alerter,
    ) {}

    /**
     * @param  array{bank_name: string, bank_account_no: string, bank_account_holder: string}  $details
     * @param  int|null  $tokenId  the portal token used, to tell an admin impersonating the login apart
     */
    public function change(Affiliate $affiliate, array $details, AffiliateUser $by, ?int $tokenId): void
    {
        $details = ['bank_name' => $details['bank_name'], 'bank_account_no' => $details['bank_account_no'], 'bank_account_holder' => $details['bank_account_holder']];

        $change = DB::transaction(function () use ($affiliate, $details, $by, $tokenId) {
            $locked = Affiliate::query()->lockForUpdate()->findOrFail($affiliate->id);

            $old = $locked->only(self::FIELDS);
            if ($old === $details) {
                return null;
            }

            $locked->forceFill($details)->save();

            return AffiliateBankChange::query()->create([
                'affiliate_id' => $locked->id,
                'affiliate_user_id' => $by->id,
                'impersonation_session_id' => $tokenId === null ? null : AffiliateImpersonationSession::query()
                    ->where('personal_access_token_id', $tokenId)->whereNull('ended_at')->value('id'),
                'old_bank_name' => $old['bank_name'],
                'old_bank_account_no' => $old['bank_account_no'],
                'old_bank_account_holder' => $old['bank_account_holder'],
                'new_bank_name' => $details['bank_name'],
                'new_bank_account_no' => $details['bank_account_no'],
                'new_bank_account_holder' => $details['bank_account_holder'],
            ]);
        });

        if ($change !== null) {
            $affiliate->forceFill($details);
            DB::afterCommit(fn () => $this->notify($affiliate, $change));
        }
    }

    private function notify(Affiliate $affiliate, AffiliateBankChange $change): void
    {
        $by = $change->impersonation_session_id !== null ? 'an admin, signed in as you' : 'your partner portal login';
        $to = sprintf('%s, account ending %s, %s', $change->new_bank_name, self::lastFour($change->new_bank_account_no), $change->new_bank_account_holder);

        $emails = AffiliateUser::query()
            ->where('owner_type', AccountOwnerType::Affiliate->value)->where('owner_id', $affiliate->id)
            ->where('is_active', true)->pluck('email')->all();
        if ($affiliate->email !== null) {
            $emails[] = $affiliate->email;
        }

        foreach (array_unique($emails) as $email) {
            try {
                $this->mailer->sendView($email, 'Your payout bank details were changed', 'emails.affiliate-bank-changed', [
                    'businessName' => $affiliate->business_name,
                    'to' => $to,
                    'by' => $by,
                ]);
            } catch (PlunkSendException $e) {
                Log::warning('Affiliate bank-change email failed', ['affiliate_id' => $affiliate->id, 'error' => $e->getMessage()]);
            }
        }

        $from = $change->old_bank_account_no === null
            ? 'none (first time set)'
            : sprintf('%s, account ending %s', $change->old_bank_name, self::lastFour($change->old_bank_account_no));

        $this->alerter->alert(
            "{$affiliate->business_name} changed their payout bank details",
            "From: {$from}\nTo: {$to}\nBy: ".($change->impersonation_session_id !== null ? 'an admin impersonating the portal login' : 'the portal login')
                ."\n\nTheir next payout ticket uses the new details. Check the change is genuine before approving it.",
            'Affiliate bank',
        );
    }

    private static function lastFour(string $accountNo): string
    {
        return substr(preg_replace('/\D/', '', $accountNo) ?: $accountNo, -4);
    }
}
