<?php

namespace App\Services\Campaign;

/**
 * ADR-118 decision 10 + V5: why a promo code was refused. A public code
 * gets a specific message about the code itself; anything about the
 * customer shares one message that never says which detail matched.
 */
enum PromoRejection: string
{
    case Invalid = 'invalid';
    case Ended = 'ended';
    case Exhausted = 'exhausted';
    case NotApplicable = 'not_applicable';
    case NotNewCustomer = 'not_new_customer';

    public function message(): string
    {
        return match ($this) {
            self::Invalid => 'This promo code is not valid.',
            self::Ended => 'This promo code has ended.',
            self::Exhausted => 'This promo code has reached its limit.',
            self::NotApplicable => 'This promo code cannot be used for this item.',
            self::NotNewCustomer => 'This promo code is for new customers only, once per customer. If you have an unfinished payment, please try again in 30 minutes.',
        };
    }
}
