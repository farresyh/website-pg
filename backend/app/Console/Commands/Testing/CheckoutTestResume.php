<?php

namespace App\Console\Commands\Testing;

use App\Models\Order;
use App\Services\Checkout\CheckoutService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Test-only helper, same pattern as OrderTestRefundToWallet: invoked as
 * a genuinely separate OS process so two resume() calls for the same
 * Order race for real (M-7, 2026-09-29 audit). The fake gateway appends
 * a line to `callsFile` on every createPayment() call — the actual
 * proof the lock added to CheckoutService::requestPayment() works is
 * that only ONE line ever lands there, not that both processes report
 * the same payment_ref (which the pre-fix code would also show, since
 * whichever update() committed last wins either way).
 */
#[Signature('app:checkout-test-resume {orderId} {callsFile} {resultFile}')]
#[Description('Test-only: resume payment for an order and write the outcome to a result file.')]
class CheckoutTestResume extends Command
{
    public function handle(CheckoutService $checkout): int
    {
        $order = Order::query()->findOrFail((int) $this->argument('orderId'));
        $callsFile = $this->argument('callsFile');
        $resultFile = $this->argument('resultFile');

        $gateway = new class($callsFile) implements PaymentGateway
        {
            public function __construct(private readonly string $callsFile) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                file_put_contents($this->callsFile, "call\n", FILE_APPEND | LOCK_EX);

                return PaymentResponse::success(['payment_request_id' => 'pr-resume-race-test']);
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
            $result = $checkout->resume($order, $gateway, 'fpx');
            file_put_contents($resultFile, $result->payment_ref ?? 'null');
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.get_class($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
