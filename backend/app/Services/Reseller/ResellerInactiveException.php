<?php

namespace App\Services\Reseller;

use RuntimeException;

/** ADR-073 decision 4: a deactivated Reseller can't place new orders on either channel. */
final class ResellerInactiveException extends RuntimeException {}
