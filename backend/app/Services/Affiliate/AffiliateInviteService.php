<?php

namespace App\Services\Affiliate;

use App\Models\AffiliateUser;
use App\Services\Membership\PlunkMailer;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;

/**
 * ADR-058: the set-password invitation a newly-created affiliate_user
 * receives by email. Mechanically a password-reset token (the
 * `affiliate_users` broker, stored in `affiliate_password_reset_tokens`,
 * 24h expiry) — "invite" and "reset" differ only in the email copy.
 *
 * 58a built `createInviteLink()` (the token round-trip, testable before
 * a caller existed); 58b adds `sendInvite()` — the RES-2 admin create
 * flow and the "resend invite" action call it, delivery via Plunk
 * (still the same pending sender-domain verification as consumer OTP,
 * ADR-027).
 */
final class AffiliateInviteService
{
    public function __construct(
        private readonly PasswordBrokerFactory $brokers,
        private readonly PlunkMailer $mailer,
    ) {}

    public function createInviteLink(AffiliateUser $user): string
    {
        $token = $this->brokers->broker('affiliate_users')->createToken($user);

        $base = rtrim((string) config('services.affiliate_portal.url'), '/');

        return $base.'/set-password?'.http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]);
    }

    public function sendInvite(AffiliateUser $user): void
    {
        $link = $this->createInviteLink($user);
        $business = $user->affiliate?->business_name ?? 'your affiliate account';

        $this->mailer->send(
            $user->email,
            'Set your affiliate portal password',
            "Hi {$user->name},\n\n"
            ."An account has been created for you to manage {$business} on the affiliate portal. "
            ."Set your password to activate it:\n\n{$link}\n\n"
            ."This link expires in 24 hours. If you weren't expecting this, ignore this email.",
        );
    }
}
