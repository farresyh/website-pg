<?php

namespace App\Services\Fulfillment;

use Illuminate\Validation\ValidationException;

/**
 * ADR-105 2026-10-06 decision 12 — what one resend of an order to one
 * package would do, as `OrderResendService::preflight()` computes it.
 * The controller's synchronous check, the locked write and the admin
 * preview all read this one object, so no caller re-derives a rule.
 */
final readonly class ResendImpact
{
    public const LOSS_MESSAGE = 'This resend would result in a loss — live cost now exceeds what was actually collected for this order. Provide an override reason to proceed anyway and accept the loss.';

    public function __construct(
        public int $costPriceSen,
        public int $costDiffSen,
        public int $affiliateProfitSen,
        public int $platformProfitSen,
        public ?string $blockedField = null,
        public ?string $blockedReason = null,
    ) {}

    /** Decision 11 — a loss on catalog cost needs a reason, on every basis. */
    public function overrideRequired(): bool
    {
        return $this->platformProfitSen < 0;
    }

    /** @throws ValidationException */
    public function assertAllowed(?string $overrideReason): void
    {
        if ($this->blockedReason !== null) {
            throw ValidationException::withMessages([$this->blockedField => [$this->blockedReason]]);
        }

        if ($this->overrideRequired() && trim((string) $overrideReason) === '') {
            throw ValidationException::withMessages(['override_reason' => [self::LOSS_MESSAGE]]);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cost_price' => $this->costPriceSen,
            'cost_diff' => $this->costDiffSen,
            'affiliate_profit' => $this->affiliateProfitSen,
            'platform_profit' => $this->platformProfitSen,
            'override_required' => $this->overrideRequired(),
            'blocked_reason' => $this->blockedReason,
        ];
    }
}
