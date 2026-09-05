<?php

namespace App\Services\Reseller;

use RuntimeException;

/**
 * PR-G planning addendum decision 3: minimum RM10 (1000 sen), no
 * platform-imposed maximum.
 */
final class InvalidWalletTopupAmountException extends RuntimeException {}
