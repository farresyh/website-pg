<?php

namespace App\Services\Reseller;

use App\Models\ResellerUser;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;

/**
 * ADR-058 (58a): mints the set-password link a newly-created
 * reseller_user receives by email. Mechanically a password-reset token
 * (the `reseller_users` broker, stored in `reseller_password_reset_tokens`)
 * — "invite" and "reset" differ only in the email copy, which is 58b's
 * concern (the admin RES-2 create flow calls this and sends via Plunk).
 *
 * Kept as a service, not inline in a controller, so 58a can test the
 * token round-trip (createInviteLink -> ResellerAuthController::setPassword)
 * before the admin create endpoint that will be its real caller exists.
 */
final class ResellerInviteService
{
    public function __construct(private readonly PasswordBrokerFactory $brokers) {}

    public function createInviteLink(ResellerUser $user): string
    {
        $token = $this->brokers->broker('reseller_users')->createToken($user);

        $base = rtrim((string) config('services.reseller_portal.url'), '/');

        return $base.'/set-password?'.http_build_query([
            'token' => $token,
            'email' => $user->email,
        ]);
    }
}
