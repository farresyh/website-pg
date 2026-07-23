<?php

namespace App\Console\Commands\Smoke;

use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\Xendit\XenditGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Manual verification tool, not part of the automated suite — the
 * whole point is hitting the real Xendit API to confirm assumptions
 * XenditGatewayTest can't (request_amount's actual unit for MYR, real
 * validation-error bodies, etc.). Safe as long as XENDIT_SECRET_KEY is
 * a xnd_development_... key, since Xendit's test/live mode is
 * determined by which key type is used, not a request flag.
 */
#[Signature('app:xendit-smoke-test')]
#[Description('Manually verify XenditGateway against the real Xendit API: creates a small test payment request and fetches it back.')]
class XenditSmokeTest extends Command
{
    public function handle(): int
    {
        $config = config('services.xendit');

        if (blank($config['secret_key'] ?? null) || blank($config['webhook_token'] ?? null)) {
            $this->error('XENDIT_SECRET_KEY / XENDIT_WEBHOOK_TOKEN not set in .env — nothing to test.');

            return self::FAILURE;
        }

        if (! str_starts_with($config['secret_key'], 'xnd_development_')) {
            $this->warn('XENDIT_SECRET_KEY does not look like a xnd_development_... key — double-check before proceeding, this creates a real payment request.');
        }

        $gateway = new XenditGateway(
            baseUrl: $config['base_url'],
            secretKey: $config['secret_key'],
            webhookToken: $config['webhook_token'],
        );

        $referenceId = 'SMOKE-'.now()->format('YmdHis');

        $this->info("Creating test payment request (reference_id: {$referenceId})...");

        $created = $gateway->createPayment(new PaymentRequest(
            referenceId: $referenceId,
            amountSen: 100,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'DUITNOW_PAY',
            channelProperties: [
                'success_return_url' => 'https://example.com/checkout/success',
                'failure_return_url' => 'https://example.com/checkout/failure',
                'pending_return_url' => 'https://example.com/checkout/pending',
            ],
        ));

        $this->printResult('createPayment', $created);

        if (! $created->success) {
            $this->error('Stopping — create failed, nothing to fetch back.');

            return self::FAILURE;
        }

        $paymentRequestId = $created->data['payment_request_id'];

        $this->info("Fetching it back by id ({$paymentRequestId})...");

        $fetched = $gateway->getPayment($paymentRequestId);

        $this->printResult('getPayment', $fetched);

        return $created->success && $fetched->success ? self::SUCCESS : self::FAILURE;
    }

    private function printResult(string $label, PaymentResponse $result): void
    {
        if ($result->success) {
            $this->info("[{$label}] success:");
            $this->line(json_encode($result->data, JSON_PRETTY_PRINT));
        } else {
            $this->error("[{$label}] failed: [{$result->errorCode}] {$result->errorMessage}");
        }
    }
}
