<?php

namespace App\Rules;

use App\Models\PaymentMethod;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * PRD §16 item 180 / ADR-022 2026-11-14 addendum: a channel a new charge may
 * use. Asks `PaymentMethod::chargeableForChannel()`, so validation and the
 * charge itself share one definition of "chargeable".
 */
final class ChargeablePaymentChannel implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PaymentMethod::chargeableForChannel($value)->exists()) {
            $fail('validation.exists')->translate();
        }
    }
}
