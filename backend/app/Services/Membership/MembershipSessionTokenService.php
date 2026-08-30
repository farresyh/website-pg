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

    public function issue(int $resellerId, string $email): string
    {
        return Crypt::encryptString(json_encode([
            'reseller_id' => $resellerId,
            'email' => $email,
            'expires_at' => now()->addDays(self::TTL_DAYS)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * ADR-061 decision 5: the token now carries the brand it was issued
     * on. Returns `['reseller_id' => int, 'email' => string]` on a valid,
     * unexpired token; `null` otherwise (including a legacy token with no
     * `reseller_id` — membership shipped seeded-off, so no such token is
     * live in production).
     *
     * @return array{reseller_id: int, email: string}|null
     */
    public function resolve(string $token): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload) || ! isset($payload['reseller_id'], $payload['email'], $payload['expires_at'])) {
            return null;
        }

        if ($payload['expires_at'] < now()->timestamp) {
            return null;
        }

        return [
            'reseller_id' => (int) $payload['reseller_id'],
            'email' => (string) $payload['email'],
        ];
    }
}
