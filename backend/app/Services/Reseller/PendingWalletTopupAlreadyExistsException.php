<?php

namespace App\Services\Reseller;

use RuntimeException;

/**
 * PR-G planning addendum decision 8: exactly one `pending`
 * `WalletTopupAttempt` per Reseller at a time — creating a new attempt
 * while one is already pending is rejected outright, not queued.
 */
final class PendingWalletTopupAlreadyExistsException extends RuntimeException {}
