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

    public function generate(string $email): string
    {
        $code = (string) random_int(10 ** (self::CODE_LENGTH_DIGITS - 1), (10 ** self::CODE_LENGTH_DIGITS) - 1);

        MembershipOtpCode::query()->create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
        ]);

        return $code;
    }

    public function verify(string $email, string $code): bool
    {
        $otp = MembershipOtpCode::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($otp === null || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return false;
        }

        $otp->update(['consumed_at' => now()]);

        return true;
    }
}
