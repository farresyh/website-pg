<?php

namespace App\Services\Order;

/**
 * Reflects the payment gateway only (Xendit, CHIP, ...) — never the
 * supplier. See DeliveryStatus.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
}
