<?php

namespace App\Services\Reseller;

use App\Services\Payment\PaymentResponse;

/**
 * ADR-135 decisions 6 and 8b: why a pending top-up ended. The one place
 * that names the attempt's ended status and picks the WhatsApp group's
 * message, so the same CHIP state never gets two labels.
 */
enum WalletTopupEndCause: string
{
    case PortalCancel = 'portal_cancel';
    case BotCancel = 'bot_cancel';
    case BankFailed = 'bank_failed';
    case Expired = 'expired';

    /** The gateway could not start the payment: no link was ever handed out. */
    case CheckoutFailed = 'checkout_failed';

    /** A final failure from the gateway: past its due time, or anything else (cancel, bank, fraud block). */
    public static function fromGateway(PaymentResponse $answer): self
    {
        return $answer->endedByExpiry() ? self::Expired : self::BankFailed;
    }

    public function status(): WalletTopupAttemptStatus
    {
        return $this === self::Expired ? WalletTopupAttemptStatus::Expired : WalletTopupAttemptStatus::Failed;
    }
}
