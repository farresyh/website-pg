<?php

namespace App\Console\Commands\Testing;

use App\Services\Checkout\CheckoutFailedException;
use App\Services\Checkout\CheckoutRequest;
use App\Services\Checkout\CheckoutService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Pricing\PaymentMethodFeeConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * CheckoutSettleWithVoucherConcurrencyTest so two checkout attempts
 * citing the same exactly-covering voucher race `initiate()` for real
 * (M-6, 2026-09-29 audit / ADR-024 addendum). Never reaches the gateway
 * (full-cover skips it entirely), so the fake gateway just needs to
 * satisfy the type hint.
 */
#[Signature('app:checkout-test-initiate-full-cover {affiliateId} {gameId} {packageId} {voucherCode} {sellingPriceSen} {idempotencyKey} {resultFile}')]
#[Description('Test-only: initiate a full-cover-by-voucher checkout and write the outcome to a result file.')]
class CheckoutTestInitiateFullCover extends Command
{
    public function handle(CheckoutService $checkout): int
    {
        $resultFile = $this->argument('resultFile');

        $gateway = new class implements PaymentGateway
        {
            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new \RuntimeException('not used in this test — full-cover never reaches the gateway');
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
                costPriceSen: 1,
                standardSellingPriceSen: (int) $this->argument('sellingPriceSen'),
                packageMarkupPercent: 0.0,
                affiliateMarkupPct: 0.0,
                paymentFeeConfig: new PaymentMethodFeeConfig(percentageRate: 0.0, flatFeeSen: 0),
                paymentMethod: 'ewallet',
                paymentGateway: 'chip',
                channelCode: 'fpx',
                idempotencyKey: $this->argument('idempotencyKey'),
                voucherCode: $this->argument('voucherCode'),
                gameId: (int) $this->argument('gameId'),
                packageId: (int) $this->argument('packageId'),
                affiliateId: (int) $this->argument('affiliateId'),
            ), $gateway);

            file_put_contents($resultFile, $order->payment_status->value);
        } catch (CheckoutFailedException $e) {
            file_put_contents($resultFile, 'checkout_failed: '.$e->getMessage());
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.get_class($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
