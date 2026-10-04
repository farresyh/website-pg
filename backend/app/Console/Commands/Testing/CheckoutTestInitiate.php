<?php

namespace App\Console\Commands\Testing;

use App\Services\Checkout\CheckoutAttemptClosedException;
use App\Services\Checkout\CheckoutRequest;
use App\Services\Checkout\CheckoutService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Pricing\PaymentMethodFeeConfig;
use App\Services\Voucher\InvalidVoucherException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * CheckoutReservationRaceConcurrencyTest so two partial-cover (or
 * member-priced) checkouts race the voucher / member-quota reservation
 * for real (ADR-024 2026-10-04 addendum). The fake gateway always
 * succeeds — the race under test is the reservation after it.
 */
#[Signature('app:checkout-test-initiate {affiliateId} {gameId} {packageId} {costPriceSen} {sellingPriceSen} {markupPercent} {idempotencyKey} {resultFile} {--voucher=} {--membership=}')]
#[Description('Test-only: initiate a gateway checkout (voucher and/or member) and write the outcome to a result file.')]
class CheckoutTestInitiate extends Command
{
    public function handle(CheckoutService $checkout): int
    {
        $resultFile = $this->argument('resultFile');

        $gateway = new class implements PaymentGateway
        {
            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => 'pr-'.$request->referenceId]);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                throw new \RuntimeException('not used in this test');
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new \RuntimeException('not used in this test');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new \RuntimeException('not used in this test');
            }
        };

        try {
            $order = $checkout->initiate(new CheckoutRequest(
                customerEmail: 'buyer@example.com',
                customerName: 'Buyer',
                customerPhone: null,
                playerId: '123456',
                serverId: null,
                costPriceSen: (int) $this->argument('costPriceSen'),
                standardSellingPriceSen: (int) $this->argument('sellingPriceSen'),
                packageMarkupPercent: (float) $this->argument('markupPercent'),
                affiliateMarkupPct: 0.0,
                paymentFeeConfig: new PaymentMethodFeeConfig(percentageRate: 0.0, flatFeeSen: 0),
                paymentMethod: 'fpx',
                paymentGateway: 'chip',
                channelCode: 'fpx',
                idempotencyKey: $this->argument('idempotencyKey'),
                voucherCode: $this->option('voucher'),
                gameId: (int) $this->argument('gameId'),
                packageId: (int) $this->argument('packageId'),
                affiliateId: (int) $this->argument('affiliateId'),
                membershipId: $this->option('membership') !== null ? (int) $this->option('membership') : null,
            ), $gateway);

            file_put_contents($resultFile, $order->payment_ref !== null ? 'linked' : $order->payment_status->value);
        } catch (CheckoutAttemptClosedException $e) {
            file_put_contents($resultFile, 'checkout_closed: '.$e->getMessage());
        } catch (InvalidVoucherException) {
            // The two processes did not overlap: the second one's preview
            // already saw the drained voucher. A legitimate outcome.
            file_put_contents($resultFile, 'rejected_at_preview');
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.get_class($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
