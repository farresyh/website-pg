<?php

namespace App\Services\Joki;

use App\Services\Pricing\PricingResolution;

/**
 * ADR-126 decisions 2, 7, 8, 13: a priced joki selection on its way into
 * an order — the Fixed pricing (at the share in force now), and the
 * `joki_orders` row OrderFactory writes in the order's own transaction.
 */
final readonly class JokiPurchase
{
    public function __construct(
        public JokiQuote $quote,
        public PricingResolution $pricing,
        public float $payoutSharePct,
        public ?string $heroRequest,
        public ?string $role,
        public ?string $playWindow,
        public ?string $termsSnapshot,
    ) {}

    /** @return array<string, mixed> */
    public function orderRow(int $orderId): array
    {
        return [
            'order_id' => $orderId,
            'from_rank' => $this->quote->from,
            'to_rank' => $this->quote->to,
            'is_express' => $this->quote->isExpress,
            'hero_request' => $this->heroRequest,
            'role' => $this->role,
            'play_window' => $this->playWindow,
            'quote' => $this->quote->snapshot(),
            'payout_share_pct' => $this->payoutSharePct,
            'terms_snapshot' => $this->termsSnapshot,
        ];
    }
}
