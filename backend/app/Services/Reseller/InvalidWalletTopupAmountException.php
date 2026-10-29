<?php

namespace App\Services\Reseller;

use RuntimeException;

/**
 * An amount the Purchase Balance cannot take: below the minimum
 * (PR-G planning addendum decision 3, RM10), above the per-top-up
 * maximum, or over the balance cap (ADR-131 decisions 4-7). The two
 * limit cases are subclasses so a caller can answer each with its own
 * sentence; catching this base class still catches all three.
 */
class InvalidWalletTopupAmountException extends RuntimeException {}
