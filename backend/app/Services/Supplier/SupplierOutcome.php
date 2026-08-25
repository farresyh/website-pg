<?php

namespace App\Services\Supplier;

/**
 * ADR-032: the normalized outcome every adapter maps its own raw
 * supplier statuses onto — business logic (OrderFulfillmentService)
 * branches on this, never on a raw status string or a supplier's
 * identity. Gamevion (synchronous) only ever produces Success/Failure;
 * an async supplier (Digiflazz) can also produce Pending.
 */
enum SupplierOutcome: string
{
    case Success = 'success';
    case Pending = 'pending';
    case Failure = 'failure';
}
