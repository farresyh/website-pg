<?php

namespace App\Services\Reseller;

use App\Models\ResellerUser;
use App\Services\Membership\PlunkMailer;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;

/**
 * ADR-058: the set-password invitation a newly-created reseller_user
 * receives by email. Mechanically a password-reset token (the
 * `reseller_users` broker, stored in `reseller_password_reset_tokens`,
 * 24h expiry) — "invite" and "reset" differ only in the email copy.
 *
 * 58a built `createInviteLink()` (the token round-trip, testable before
 * a caller existed); 58b adds `sendInvite()` — the RES-2 admin create
 * flow and the "resend invite" action call it, delivery via Plunk
 * (still the same pending sender-domain verification as consumer OTP,
 * ADR-027).
 */
final class ResellerInviteService
{
    public function __construct(
        private readonly PasswordBrokerFactory $brokers,
        private readonly PlunkMailer $mailer,
    ) {}

    public function createInviteLink(ResellerUser $user): string
    {
        $token = $this->brokers->broker('reseller_users')->createToken($user);

        $base = rtrim((string) config('services.reseller_portal.url'), '/');

        return $base.'/set-password?'.http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]);
    }

    public function sendInvite(ResellerUser $user): void
    {
        $link = $this->createInviteLink($user);
        $business = $user->reseller?->business_name ?? 'your reseller account';

        $this->mailer->send(
            $user->email,
            'Set your reseller portal password',
            "Hi {$user->name},\n\n"
            ."An account has been created for you to manage {$business} on the reseller portal. "
            ."Set your password to activate it:\n\n{$link}\n\n"
            ."This link expires in 24 hours. If you weren't expecting this, ignore this email.",
        );
    }
}
