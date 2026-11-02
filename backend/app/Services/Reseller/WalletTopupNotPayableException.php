<?php

namespace App\Services\Reseller;

use RuntimeException;

/** ADR-135 decision 2: resume found no payable link (ended, paid, or not set up yet). */
final class WalletTopupNotPayableException extends RuntimeException {}
