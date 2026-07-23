<?php

namespace App\Services\Fulfillment;

use RuntimeException;

/**
 * An order's data isn't ready for fulfillment (distinct from
 * InvalidOrderTransitionException, which is specifically about the
 * payment/delivery state-machine guard).
 */
final class OrderFulfillmentException extends RuntimeException
{
}
