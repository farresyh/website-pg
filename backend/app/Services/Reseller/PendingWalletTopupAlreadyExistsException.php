<?php

namespace App\Services\Reseller;

use App\Models\WalletTopupAttempt;
use RuntimeException;

/**
 * PR-G planning addendum decision 8: exactly one `pending`
 * `WalletTopupAttempt` per Reseller at a time — creating a new attempt
 * while one is already pending is rejected outright, not queued.
 *
 * ADR-135 decision 4: carries the attempt the wallet lock found, so a
 * caller renders that one instead of querying again. The message is
 * shown to the reseller as is (the portal's toast).
 */
final class PendingWalletTopupAlreadyExistsException extends RuntimeException
{
    public function __construct(public readonly WalletTopupAttempt $attempt)
    {
        parent::__construct('You already have a payment waiting. Pay it or cancel it before starting another.');
    }
}
