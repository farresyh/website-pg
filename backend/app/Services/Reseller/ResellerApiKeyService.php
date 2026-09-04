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
     * touching `last_used_at` on a hit. Null for an unknown or
     * already-revoked key — the caller (`EnsureResellerApiKey`)
     * doesn't need to distinguish the two, both reject the request
     * the same way.
     */
    public function resolve(string $plainText): ?ResellerApiKey
    {
        $key = ResellerApiKey::query()
            ->where('key_hash', $this->hash($plainText))
            ->whereNull('revoked_at')
            ->first();

        $key?->update(['last_used_at' => now()]);

        return $key;
    }

    /** Idempotent — revoking an already-revoked key is a no-op, not an error. */
    public function revoke(ResellerApiKey $key): void
    {
        if (! $key->isRevoked()) {
            $key->update(['revoked_at' => now()]);
        }
    }

    private function hash(string $plainText): string
    {
        return hash('sha256', $plainText);
    }
}
