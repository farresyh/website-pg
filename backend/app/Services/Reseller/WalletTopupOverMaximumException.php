<?php

namespace App\Services\Reseller;

/** ADR-131 decision 7: the amount is above the per-top-up maximum. */
final class WalletTopupOverMaximumException extends InvalidWalletTopupAmountException
{
    public function __construct(public readonly int $maxSen)
    {
        parent::__construct('The most you can add in one go is RM'.number_format($maxSen / 100, 2).'.');
    }
}
