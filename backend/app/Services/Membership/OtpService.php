<?php

namespace App\Services\Membership;

use App\Models\MembershipOtpCode;
use Illuminate\Support\Facades\Hash;

/**
 * ADR-027's 2026-08-29 addendum, decisions 23/26/27: identity
 * verification is a 6-digit code, hashed at rest, expiring in 10
 * minutes, locked out after 5 wrong attempts on the same code — a
 * second, independent anti-abuse layer from decision 26's
 * per-email request throttle (that limits how often a code can be
 * *requested*; this limits how many times one can be *guessed*).
 */
final class OtpService
{
    private const CODE_LENGTH_DIGITS = 6;

    private const EXPIRY_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function generate(int $affiliateId, string $email): string
    {
        $code = (string) random_int(10 ** (self::CODE_LENGTH_DIGITS - 1), (10 ** self::CODE_LENGTH_DIGITS) - 1);

        MembershipOtpCode::query()->create([
            'affiliate_id' => $affiliateId,
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
        ]);

        return $code;
    }

    /**
     * ADR-061 decision 5: a code is only valid on the brand that issued
     * it — `affiliate_id` is part of the match, never trusted from the
     * client (the caller resolves it from the storefront `Host`, the
     * primary brand for now).
     */
    public function verify(int $affiliateId, string $email, string $code): bool
    {
        $otp = MembershipOtpCode::query()
            ->where('affiliate_id', $affiliateId)
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($otp === null) {
            return false;
        }

        // Wave 3 PR-B: claim an attempt slot atomically. A read-then-
        // increment let N parallel requests at attempts=4 each get a guess.
        $claimed = MembershipOtpCode::query()
            ->whereKey($otp->id)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($claimed === 0 || ! Hash::check($code, $otp->code_hash)) {
            return false;
        }

        // Same for consumption: only one request may redeem the code.
        return MembershipOtpCode::query()
            ->whereKey($otp->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]) === 1;
    }
}
