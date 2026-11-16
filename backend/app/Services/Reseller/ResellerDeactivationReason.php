<?php

namespace App\Services\Reseller;

/**
 * ADR-142 decision 10: why a reseller is deactivated. `Manual` is the admin's
 * switch; `Dormant` is the daily dormancy job; `Closed` is Close account
 * (ADR-131), which also soft-deletes the reseller.
 */
enum ResellerDeactivationReason: string
{
    case Manual = 'manual';
    case Dormant = 'dormant';
    case Closed = 'closed';
}
