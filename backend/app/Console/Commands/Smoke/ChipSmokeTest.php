<?php

namespace App\Console\Commands\Smoke;

use App\Services\Payment\Chip\ChipCredentialResolver;
use App\Services\Payment\Chip\ChipGateway;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Manual verification tool, not part of the automated suite — mirrors
 * the existing app:gamevion-smoke-test's own role: hitting the real CHIP API to
 * confirm assumptions ChipGatewayTest's Http::fake()-based coverage
 * can't (real request/response shapes, real error bodies, whether the
 * FPX channel actually accepts this account's brand_id). Per ADR-022
 * decision 5, `payment_methods.gateway` must never be flipped to
 * `'chip'` for a live channel, and the adapter must never be called
 * "verified," until this command passes against a real account.
 *
 * Unlike Xendit, CHIP has no separate sandbox base URL and no
 * documented test-key prefix convention (confirmed unconfirmed
 * against CHIP's own docs, ADR-022 decision 5) — running this always
 * hits the real production endpoint. Whether a given CHIP_SECRET_KEY
 * is a test-mode key is something only CHIP's own dashboard can
 * confirm; this command cannot detect it the way Xendit's smoke test
 * detects a `xnd_development_...` prefix.
 */
#[Signature('app:chip-smoke-test')]
#[Description('Manually verify ChipGateway against the real CHIP API: creates a small test purchase and fetches it back.')]
class ChipSmokeTest extends Command
{
    public function handle(ChipCredentialResolver $credentialResolver): int
    {
        // ADR-110 PR-C — resolves identically to real production
        // traffic (the `payment-gateway.chip` container binding):
        // `payment_gateways` DB row first, `.env` fallback per-field.
        // See `ChipCredentialResolver`'s own doc comment for why this
        // command doesn't just read `config('services.chip')` directly.
        $credentials = $credentialResolver->resolve();

        if (blank($credentials['secret_key']) || blank($credentials['brand_id'])) {
            $this->error('No CHIP secret_key/brand_id configured — set them via /middleware/payment-gateways or CHIP_SECRET_KEY/CHIP_BRAND_ID in .env — nothing to test.');

            return self::FAILURE;
        }

        $this->warn('CHIP has no separate sandbox endpoint — this hits the real production API. Confirm the configured secret_key is a test-mode key in the CHIP dashboard before proceeding.');

        $gateway = new ChipGateway(
            baseUrl: $credentials['base_url'],
            secretKey: $credentials['secret_key'],
            brandId: $credentials['brand_id'],
        );

        $referenceId = 'SMOKE-'.now()->format('YmdHis');

        $this->info("Creating test purchase (reference: {$referenceId})...");

        $created = $gateway->createPayment(new PaymentRequest(
            referenceId: $referenceId,
            amountSen: 100,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'fpx',
            channelProperties: [
                'success_return_url' => 'https://example.com/checkout/success',
                'failure_return_url' => 'https://example.com/checkout/failure',
            ],
            description: 'ChipSmokeTest purchase',
            customer: new PaymentCustomer(
                referenceId: $referenceId,
                givenNames: 'Smoke Test',
                email: 'smoke-test@example.com',
            ),
        ));

        $this->printResult('createPayment', $created);

        if (! $created->success) {
            $this->error('Stopping — create failed, nothing to fetch back.');

            return self::FAILURE;
        }

        $purchaseId = $created->data['payment_request_id'];

        $this->info("Fetching it back by id ({$purchaseId})...");

        $fetched = $gateway->getPayment($purchaseId);

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
