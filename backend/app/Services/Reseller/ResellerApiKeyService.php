<?php

namespace App\Services\Reseller;

use App\Models\Reseller;
use App\Models\ResellerApiKey;
use Illuminate\Support\Str;

/**
 * ADR-074 decision 1: the ONE seam that generates, resolves, and
 * revokes a `Reseller` (wallet) account's Reseller API credential —
 * `EnsureResellerApiKey` (the HTTP auth boundary) and
 * `Admin\ResellerApiKeyController` (the admin issue/revoke screen)
 * both call this, neither hashes or compares a key itself.
 *
 * A key is shown in plaintext exactly once, in `issue()`'s return
 * value — nothing this service does afterward can ever recover it,
 * only `key_hash` is persisted (mirrors Sanctum's own
 * `PersonalAccessToken` trust model).
 */
final class ResellerApiKeyService
{
    private const KEY_PREFIX = 'pgrk_';

    /**
     * @return array{key: ResellerApiKey, plainText: string}
     */
    public function issue(Reseller $reseller, string $name): array
    {
        $plainText = self::KEY_PREFIX.Str::random(48);

        $key = ResellerApiKey::query()->create([
            'reseller_id' => $reseller->id,
            'name' => $name,
            'key_hash' => $this->hash($plainText),
        ]);

        return ['key' => $key, 'plainText' => $plainText];
    }

    /**
     * Resolves a bearer token to its still-live `ResellerApiKey`,
     * stamping `last_used_at` (and `last_used_ip` when known — ADR-084
     * PR-1 decision 6) on a hit. Null for an unknown or already-revoked
     * key — the caller (`EnsureResellerApiKey`) doesn't need to
     * distinguish the two, both reject the request the same way.
     */
    public function resolve(string $plainText, ?string $ip = null): ?ResellerApiKey
    {
        $key = ResellerApiKey::query()
            ->where('key_hash', $this->hash($plainText))
            ->whereNull('revoked_at')
            ->first();

        if ($key !== null && $this->shouldStamp($key, $ip)) {
            $key->update(array_filter([
                'last_used_at' => now(),
                'last_used_ip' => $ip,
            ], fn ($value) => $value !== null));
        }

        return $key;
    }

    /**
     * A2 hardening (2026-09-10 reseller-family audit, `docs/build-log.md`):
     * the stamp used to write on every single authenticated call — a
     * write-per-request under high QPS from one key. Skips the write once
     * `last_used_at` is fresh (<60s, matching the catalog cache TTL) —
     * unless the IP has changed, which always stamps immediately
     * regardless of freshness: that's exactly the anomaly signal
     * `last_used_ip` exists to surface (see `EnsureResellerApiKey`'s own
     * doc comment — a request later rejected by the IP allowlist still
     * gets stamped, deliberately, and this throttle doesn't touch that).
     */
    private function shouldStamp(ResellerApiKey $key, ?string $ip): bool
    {
        if ($key->last_used_at === null) {
            return true;
        }

        if ($ip !== null && $ip !== $key->last_used_ip) {
            return true;
        }

        return $key->last_used_at->diffInSeconds(now()) >= 60;
    }

    /** Idempotent — revoking an already-revoked key is a no-op, not an error. */
    public function revoke(ResellerApiKey $key): void
    {
        if (! $key->isRevoked()) {
            $key->update(['revoked_at' => now()]);
        }
    }

    /**
     * ADR-084 PR-1 decision 6 / PR-4: replace one key's IP allowlist. An
     * empty list is stored as `[]` = "any IP" (the opt-in default), never
     * as a lockout. `EnsureResellerApiKey` does the exact-match check.
     *
     * @param  list<string>  $ips
     */
    public function setAllowedIps(ResellerApiKey $key, array $ips): void
    {
        $key->update(['allowed_ips' => array_values(array_unique($ips))]);
    }

    private function hash(string $plainText): string
    {
        return hash('sha256', $plainText);
    }
}
