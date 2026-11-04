<?php

namespace App\Services\Campaign;

use App\Models\Game;
use App\Models\Order;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * ADR-118 V5: who is checking out, for the new-customer rule. The one
 * place two customers are compared, used by the rule (V5), the
 * attribution of later orders (V6) and cancel-and-replace (V8).
 *
 * A player account is scoped to its game family (MLBB's regional games
 * share one account), else to the game itself, so two games' id spaces
 * never collide.
 */
final readonly class PromoCustomer
{
    public function __construct(
        public string $email,
        public ?string $phone,
        public string $accountScope,
        public string $playerId,
        public ?string $serverId,
    ) {}

    public static function at(Game $game, string $email, ?string $phone, string $playerId, ?string $serverId): self
    {
        return new self($email, $phone, self::scopeOf($game->family, $game->id), $playerId, $serverId);
    }

    /** The order's `game` relation must carry `family` (callers eager-load `game:id,family`). */
    public static function forOrder(Order $order): self
    {
        return new self(
            $order->customer_email,
            $order->customer_phone,
            self::scopeOf($order->game?->family, (int) $order->game_id),
            (string) $order->player_id,
            $order->server_id,
        );
    }

    /** Any one detail matches: the same customer for the new-customer rule. */
    public function sameAs(self $other): bool
    {
        return $this->emailMatches($other) || PhoneNumber::same($this->phone, $other->phone) || $this->accountMatches($other);
    }

    /** Every detail matches: safe to cancel the other checkout for this one (V8). */
    public function identicalTo(self $other): bool
    {
        return $this->emailMatches($other) && PhoneNumber::same($this->phone, $other->phone) && $this->accountMatches($other);
    }

    public function matchesContact(?string $email, ?string $phone): bool
    {
        return ($email !== null && Str::lower(trim($email)) === Str::lower(trim($this->email))) || PhoneNumber::same($this->phone, $phone);
    }

    private function emailMatches(self $other): bool
    {
        return Str::lower(trim($this->email)) === Str::lower(trim($other->email));
    }

    private function accountMatches(self $other): bool
    {
        return $this->accountScope === $other->accountScope
            && trim($this->playerId) === trim($other->playerId)
            && self::server($this->serverId) === self::server($other->serverId);
    }

    private static function server(?string $serverId): ?string
    {
        $serverId = $serverId === null ? null : trim($serverId);

        return $serverId === '' ? null : $serverId;
    }

    private static function scopeOf(?string $family, int $gameId): string
    {
        return filled($family) ? 'family:'.Str::lower($family) : 'game:'.$gameId;
    }
}
