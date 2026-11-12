<?php

namespace App\Services\Affiliate;

use App\Models\AffiliateUser;
use App\Services\Membership\PlunkMailer;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

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
 *
 * ADR-072 decision 5 / PR-G planning addendum decision 1: generalized to
 * any `affiliate_users` owner kind — a `Reseller` (wallet) account's
 * admin-triggered invite (Admin\ResellerController::storeUser()) reuses
 * this exact seam, not a forked copy. `AffiliateUser::ownerBusinessName()`
 * is the one place the owner_type branch lives.
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

        // Deliberately still 'reseller_portal' (config/services.php), not
        // 'affiliate_portal' — ADR-072 PR-A's own judgment call kept this
        // config key/env var name as-is (the portal app is shared by both
        // Affiliate and Reseller-wallet accounts). Getting this wrong here
        // fails silently: config() returns null, (string) casts it to '',
        // and the invite link becomes a bare relative path with no host —
        // exactly the bug a real production invite email surfaced.
        return self::setPasswordUrl($token, $user->email);
    }

    private static function setPasswordUrl(string $token, string $email): string
    {
        $base = rtrim((string) config('services.reseller_portal.url'), '/');

        return $base.'/set-password?'.http_build_query(['token' => $token, 'email' => $email]);
    }

    /**
     * ADR-058 2026-11-12 addendum, decision 3: the one way a reset link is
     * sent, from the portal's Forgot password and from the admin button.
     * Through the broker's sendResetLink(), so its 60-second per-user
     * throttle holds on both paths. The link is the invite's (same broker,
     * 24 hours, single use). An inactive user is never sent one.
     *
     * @return string a Password broker status (RESET_LINK_SENT, RESET_THROTTLED, INVALID_USER)
     */
    public function sendPasswordReset(AffiliateUser $user): string
    {
        if (! $user->is_active) {
            return Password::INVALID_USER;
        }

        return $this->brokers->broker('affiliate_users')->sendResetLink(
            ['email' => $user->email],
            fn (AffiliateUser $user, string $token) => $this->mailer->sendView(
                $user->email,
                'Reset your portal password',
                'emails.affiliate-password-reset',
                [
                    'name' => $user->name,
                    'business' => $user->ownerBusinessName() ?? 'your account',
                    'url' => self::setPasswordUrl($token, $user->email),
                ],
            ),
        );
    }

    public function sendInvite(AffiliateUser $user): void
    {
        $link = $this->createInviteLink($user);
        $business = $user->ownerBusinessName() ?? 'your account';

        $this->mailer->sendView(
            $user->email,
            'Set your portal password',
            'emails.affiliate-invite',
            [
                'name' => $user->name,
                'business' => $business,
                'url' => $link,
            ]
        );
    }

    /**
     * ADR-058 2026-11-12 addendum, decision 2: the admin button on the
     * Reseller and Affiliate user lists. No password yet: the invite. A
     * password: a reset link. An inactive user: refused.
     *
     * @return string the message the admin sees
     */
    public function sendInviteOrReset(AffiliateUser $user): string
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages(['user' => ['This login is deactivated, so no link was sent.']]);
        }

        if ($user->password === null) {
            $this->sendInvite($user);

            return 'Invite re-sent.';
        }

        $status = $this->sendPasswordReset($user);
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['user' => ['A reset link was sent less than a minute ago. Wait a minute before sending another.']]);
        }

        return "Password reset link sent to {$user->email}.";
    }
}
