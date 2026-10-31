<?php

namespace App\Services\PartnerApplication;

use App\Models\PartnerApplication;
use App\Services\Membership\PlunkMailer;

/**
 * ADR-133: the three emails an applicant receives. English (decision 19). Callers send them inside
 * the transition's transaction, so a mail failure leaves the application unchanged and the action
 * can be retried. Each link goes only to the email given at stage 1.
 */
final class PartnerApplicationMailer
{
    public function __construct(private readonly PlunkMailer $mailer) {}

    public function verifyEmail(PartnerApplication $application, string $token): void
    {
        $this->mailer->sendView($application->email, 'Confirm your email for your PekanGame reseller application', 'emails.partner-application-verify', [
            'name' => $application->owner_name,
            'url' => self::url('/apply/verify?'.http_build_query(['token' => $token])),
        ]);
    }

    public function stageTwoLink(PartnerApplication $application, string $token): void
    {
        $this->mailer->sendView($application->email, 'Continue your PekanGame reseller application', 'emails.partner-application-stage-two', [
            'name' => $application->owner_name,
            'url' => self::url('/onboard/'.$token),
        ]);
    }

    public function declined(PartnerApplication $application): void
    {
        $this->mailer->sendView($application->email, 'Your PekanGame reseller application', 'emails.partner-application-declined', [
            'name' => $application->owner_name,
        ]);
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('services.onboarding.url'), '/').$path;
    }
}
