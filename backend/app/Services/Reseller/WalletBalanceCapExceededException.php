<?php

namespace App\Services\Reseller;

/** ADR-131 decision 7: the credit would take the Purchase Balance above its cap. */
final class WalletBalanceCapExceededException extends InvalidWalletTopupAmountException
{
    public function __construct(public readonly int $capSen, public readonly int $headroomSen)
    {
        parent::__construct(
            'This would take your Purchase Balance above its limit of RM'.number_format($capSen / 100, 2)
            .'. You can add up to RM'.number_format($headroomSen / 100, 2).' now.',
        );
    }
}
