<?php

namespace App\Services\Accounting;

use App\Models\ChipSettledTransaction;
use App\Models\MembershipCheckoutAttempt;
use App\Models\Order;
use App\Models\WalletTopupAttempt;
use App\Services\Membership\MembershipSubscriptionService;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\AttemptPaymentOutcome;
use App\Services\Payment\OrderPaymentOutcome;
use App\Services\Payment\OrderPaymentOutcomeService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Reseller\ResellerWalletService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ADR-135 decision 13: Re-check with CHIP, for a payment CHIP settled whose
 * record here is not paid. Asks CHIP and, on Paid, hands the answer to that
 * record's own paid seam: the one the webhook and reconcile use, with the
 * amount check and the lock inside. Never a manual credit, which is not tied
 * to the record and would double up with a late webhook. A settlement row is
 * always a CHIP payment, so the gateway is CHIP.
 */
final class SettlementRecheckService
{
    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly OrderPaymentOutcomeService $orders,
        private readonly MembershipSubscriptionService $memberships,
        private readonly ResellerWalletService $wallets,
    ) {}

    /** @return array{outcome: string, message: string} */
    public function recheck(ChipSettledTransaction $transaction): array
    {
        $record = $transaction->matchedRecord();

        if ($record === null) {
            throw ValidationException::withMessages(['transaction' => ['This transaction matches no record here, so there is nothing to re-check.']]);
        }

        $answer = $this->gateways->make('chip')->getPayment($transaction->transaction_id);

        $result = match (true) {
            ! $answer->success => ['chip_unreachable', 'Could not reach CHIP. Nothing changed; try again shortly.'],
            $answer->status !== PaymentStatus::Paid => ['not_paid_at_chip', 'CHIP says this payment is '.($answer->data['status'] ?? 'not paid').'. Nothing changed.'],
            default => $this->applyPaid($record, $answer->data['amount_sen'] ?? null, $answer->data['fee_sen'] ?? null, $answer->data['paid_on'] ?? null),
        };

        Log::info('Settlement re-check with CHIP', [
            'chip_settled_transaction_id' => $transaction->id,
            'transaction_id' => $transaction->transaction_id,
            'outcome' => $result[0],
        ]);

        return ['outcome' => $result[0], 'message' => $result[1]];
    }

    /** @return array{0: string, 1: string} */
    private function applyPaid(Order|MembershipCheckoutAttempt|WalletTopupAttempt $record, mixed $amountSen, mixed $feeSen, mixed $paidOn): array
    {
        $amountSen = is_int($amountSen) ? $amountSen : null;
        $feeSen = is_int($feeSen) ? $feeSen : null;

        if ($record instanceof Order) {
            return match ($this->orders->applyPaid($record, $amountSen, is_int($paidOn) ? $paidOn : null, $feeSen)) {
                OrderPaymentOutcome::Fulfilling => ['applied', 'Recorded as paid. The order was sent for delivery.'],
                OrderPaymentOutcome::FlaggedForReview => ['flagged_for_review', 'Recorded as paid. The order had already failed, so it went to Needs Review: decide there whether to deliver.'],
                OrderPaymentOutcome::AmountMismatch => $this->amountMismatch($amountSen, $record->final_amount),
                OrderPaymentOutcome::AlreadyProcessed, OrderPaymentOutcome::Failed => ['already_paid', 'Already paid here. Nothing changed.'],
            };
        }

        $outcome = $record instanceof WalletTopupAttempt
            ? $this->wallets->applyPaid($record, $amountSen, $feeSen)
            : $this->memberships->applyPaid($record, $amountSen, $feeSen);

        return match ($outcome) {
            AttemptPaymentOutcome::Applied => ['applied', $record instanceof WalletTopupAttempt
                ? 'Recorded as paid. The reseller\'s Purchase Balance was credited.'
                : 'Recorded as paid. The membership was activated.'],
            AttemptPaymentOutcome::AppliedToClosedAccount => ['applied_to_closed_account', 'Recorded as paid, but the reseller account is closed: refund the payer.'],
            AttemptPaymentOutcome::AmountMismatch => $this->amountMismatch($amountSen, $record->total_charged_sen),
            AttemptPaymentOutcome::AlreadyProcessed => ['already_paid', 'Already paid here. Nothing changed.'],
        };
    }

    /** @return array{0: string, 1: string} */
    private function amountMismatch(?int $chipSen, int $recordSen): array
    {
        $chip = $chipSen === null ? 'no amount' : 'RM'.number_format($chipSen / 100, 2);

        return ['amount_mismatch', "CHIP reports {$chip}, this record charged RM".number_format($recordSen / 100, 2).'. Nothing changed: check it by hand.'];
    }
}
