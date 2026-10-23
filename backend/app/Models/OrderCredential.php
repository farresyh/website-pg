<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-124: a customer's game-account login for one order. Only this side
 * of the relation exists — `Order` never gets a `credentials()` relation
 * (decision 1, OrderCredentialTest), so no `->with()` / `toArray()` on an
 * order can pull it. `secrets` is never in toArray(); it is read only by
 * OrderCredentialController::reveal() and wiped by
 * WipeOrderCredentialsCommand.
 *
 * `fields`: non-secret values, `[{key, label, value}]`.
 * `secret_fields`: the secret fields' labels, `[{key, label}]`, kept after a wipe.
 * `secrets`: `{key: value}`, encrypted with APP_KEY (`previous_keys` rotation).
 */
class OrderCredential extends Model
{
    protected $fillable = [
        'order_id',
        'fields',
        'secret_fields',
        'secrets',
        'secrets_set_at',
        'secrets_expire_at',
        'consented_at',
        'consent_text',
    ];

    protected $hidden = ['secrets'];

    protected $casts = [
        'fields' => 'array',
        'secret_fields' => 'array',
        'secrets' => 'encrypted:array',
        'secrets_set_at' => 'datetime',
        'secrets_expire_at' => 'datetime',
        'closed_at' => 'datetime',
        'purged_at' => 'datetime',
        'consented_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * ADR-125 decision 44: the login's secrets were wiped (closed past the
     * grace window, or the `credential_max_days` ceiling while still open),
     * so a re-post would reach the supplier without them.
     */
    public static function isWipedFor(int $orderId): bool
    {
        return self::query()->where('order_id', $orderId)->whereNotNull('purged_at')->exists();
    }

    /**
     * ADR-126 2026-10-23 addendum, decision 22: the one rule for a login's
     * wipe ceiling, stored on the row by every writer of `secrets_set_at`:
     * the game's own `credential_max_days` when set, else the Customer
     * Login Policy's D.
     */
    public static function expiryFor(Order $order, CarbonInterface $setAt): CarbonInterface
    {
        $order->loadMissing('game:id,credential_max_days');
        $days = $order->game?->credential_max_days ?? PlatformSettings::current()->credential_max_days;

        return $setAt->copy()->addDays($days);
    }

    /**
     * D or a game's days changed: the unwiped logins it governs move with
     * it, as they did when the sweep read D live. A wiped row is left be.
     */
    public static function refreshExpiry(?int $gameId = null): void
    {
        self::query()
            ->whereNull('purged_at')
            ->when($gameId !== null, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('game_id', $gameId)))
            ->with('order.game:id,credential_max_days')
            ->get()
            ->each(fn (self $credential) => $credential->update([
                'secrets_expire_at' => self::expiryFor($credential->order, $credential->secrets_set_at),
            ]));
    }

    public function hasSecretField(string $key): bool
    {
        return collect($this->secret_fields)->contains('key', $key);
    }

    /** What every admin who can open the order sees: never a secret value. */
    public function adminSummary(): array
    {
        return [
            'fields' => $this->fields,
            'secret_fields' => $this->secret_fields,
            'secrets_set_at' => $this->secrets_set_at?->toISOString(),
            'secrets_expire_at' => $this->secrets_expire_at?->toISOString(),
            'purged_at' => $this->purged_at?->toISOString(),
            'consented_at' => $this->consented_at?->toISOString(),
            'consent_text' => $this->consent_text,
        ];
    }
}
