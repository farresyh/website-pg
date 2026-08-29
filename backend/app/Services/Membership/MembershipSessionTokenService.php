<?php

namespace App\Services\Membership;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * ADR-027's 2026-08-29 addendum, decisions 16/17/23: a stateless,
 * signed (APP_KEY-backed) "verified session" token — no new session/
 * login table. Deliberately lighter than a full account system
 * (decision 2's own framing), reusing Laravel's own encryption the
 * same way `Supplier.api_config`/`AdminUser.mfa_secret` already do,
 * rather than inventing a new secret or a DB-backed token table this
 * pilot doesn't need (decision 12: no anti-abuse machinery pre-built
 * beyond what's already decided).
 */
final class MembershipSessionTokenService
{
    private const TTL_DAYS = 30;

    public function issue(string $email): string
    {
        return Crypt::encryptString(json_encode([
            'email' => $email,
            'expires_at' => now()->addDays(self::TTL_DAYS)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function resolve(string $token): ?string
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload) || ! isset($payload['email'], $payload['expires_at'])) {
            return null;
        }

        if ($payload['expires_at'] < now()->timestamp) {
            return null;
        }

        return $payload['email'];
    }
}
