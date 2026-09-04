<?php

namespace App\Services\Reseller;

use RuntimeException;

/** A Reseller with no reseller_tier_id has no price to place an order at. */
final class NoResellerTierAssignedException extends RuntimeException {}
