<?php

namespace App\Services\Pricing;

use App\Models\PaymentMethod;
use RuntimeException;

/**
 * SET-11: resolves the configured fee rate for a channel from the
 * `payment_methods` table (2026-07-25 — replaces the
 * config/checkout.php stopgap; see that migration's own doc comment
 * for why this is admin-curated rather than synced). Callers
 * (CreateCheckoutRequest) validate `channel_code` exists and
 * `is_active` in that table before this is ever reached, so a missing
 * row here means a race (deactivated between validation and this
 * call), not a bad client request.
 */
final class PaymentMethodFeeResolver
{
    public function resolve(string $channelCode): PaymentMethodFeeConfig
    {
        $paymentMethod = PaymentMethod::query()
            ->where('channel_code', $channelCode)
            ->where('is_active', true)
            ->first();

        if ($paymentMethod === null) {
            throw new RuntimeException("No active payment method for channel_code: {$channelCode}");
        }

        return new PaymentMethodFeeConfig(
            percentageRate: (float) $paymentMethod->percentage_rate,
            flatFeeSen: (int) $paymentMethod->flat_fee_sen,
        );
    }
}
