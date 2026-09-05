<?php

namespace App\Console\Commands\Testing;

use App\Models\Reseller;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Reseller\PendingWalletTopupAlreadyExistsException;
use App\Services\Reseller\ResellerWalletTopupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * tests/Concurrency/ResellerWalletTopupConcurrencyTest.php so two
 * self-serve top-up initiations race for real against the same
 * reseller's own `ledger_accounts` mutex row (separate PHP process +
 * separate DB connection each) — same shape as
 * ResellerOrderPlacementTestPlaceOrder. Binds a hardcoded
 * always-succeeding fake PaymentGateway directly here (never a real
 * CHIP call) — the thing being proven is the "one pending attempt"
 * lock, not payment-gateway connectivity.
 */
#[Signature('app:reseller-wallet-topup-test-initiate {resellerId} {resultFile}')]
#[Description('Test-only: attempt a single wallet top-up initiation and write the outcome to a result file.')]
class ResellerWalletTopupTestInitiate extends Command
{
    public function handle(ResellerWalletTopupService $service): int
    {
        $reseller = Reseller::query()->findOrFail((int) $this->argument('resellerId'));
        $resultFile = $this->argument('resultFile');

        $this->laravel->bind('payment-gateway.chip', fn () => new class implements PaymentGateway
        {
            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return PaymentResponse::success([
                    'payment_request_id' => 'pr-'.$request->referenceId,
                    'actions' => [['type' => 'REDIRECT', 'value' => 'https://gate.chip-in.asia/p/'.$request->referenceId]],
                ]);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used in this test');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used in this test');
            }
        });

        try {
            $attempt = $service->initiate($reseller, 5000, 'fpx', []);
            file_put_contents($resultFile, 'success:'.$attempt->reference);
        } catch (PendingWalletTopupAlreadyExistsException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
