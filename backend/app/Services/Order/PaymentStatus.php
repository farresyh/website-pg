<?php

namespace App\Services\Order;

/**
 * Reflects Xendit only — never the supplier. See DeliveryStatus.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
}
