<?php

namespace App\Services\Order;

/**
 * Reflects the Supplier API only — never the payment gateway.
 * See PaymentStatus. These are two independent state machines (ORD-11).
 */
enum DeliveryStatus: string
{
    case NotStarted = 'not_started';
    case Processing = 'processing';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
