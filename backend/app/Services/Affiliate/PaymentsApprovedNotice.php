<?php

namespace App\Services\Affiliate;

use App\Models\AffiliateDomain;
use App\Models\AffiliateUser;
use App\Services\Auth\AccountOwnerType;
use App\Services\Membership\PlunkMailer;
use App\Services\Membership\PlunkSendException;
use Illuminate\Support\Facades\Log;

/**
 * ADR-133 decision 17: tell the affiliate when an admin approves their domain for payments (the
 * portal says "we will let you know"). Sent to each active portal login, or to the affiliate's
 * contact email when there is none. A mail failure is logged and never undoes the approval.
 */
final class PaymentsApprovedNotice
{
    public function __construct(private readonly PlunkMailer $mailer) {}

    public function send(AffiliateDomain $domain): void
    {
        $affiliate = $domain->affiliate;

        $emails = AffiliateUser::query()
            ->where('owner_type', AccountOwnerType::Affiliate->value)->where('owner_id', $domain->affiliate_id)
            ->where('is_active', true)->pluck('email')->all();

        if ($emails === [] && $affiliate?->email !== null) {
            $emails = [$affiliate->email];
        }

        foreach (array_unique($emails) as $email) {
            try {
                $this->mailer->sendView(
                    $email,
                    "Payments are now on for {$domain->hostname}",
                    'emails.payments-approved',
                    ['hostname' => $domain->hostname, 'url' => 'https://'.$domain->hostname],
                );
            } catch (PlunkSendException $e) {
                Log::warning('Payments-approved email failed', ['domain_id' => $domain->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
