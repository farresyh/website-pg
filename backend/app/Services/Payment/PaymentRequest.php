<?php

namespace App\Services\Payment;

/**
 * Canonical outgoing payment request. Amount is integer sen (same
 * convention as CheckoutTotal/PricingBreakdown/LedgerEntry) — each
 * gateway adapter owns converting to whatever unit its own API expects
 * (ADAPT-4-equivalent for payment gateways).
 */
final class PaymentRequest
{
    public function __construct(
        public readonly string $referenceId,
        public readonly int $amountSen,
        public readonly string $currency,
        public readonly string $country,
        public readonly string $channelCode,
        public readonly array $channelProperties = [],
        public readonly ?string $description = null,
        public readonly array $metadata = [],
        public readonly ?PaymentCustomer $customer = null,
    ) {
    }
}
