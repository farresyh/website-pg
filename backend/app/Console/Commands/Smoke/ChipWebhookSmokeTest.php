<?php

namespace App\Console\Commands\Smoke;

use App\Models\Affiliate;
use App\Models\Order;
use App\Models\Package;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\Chip\ChipCredentialResolver;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Manual verification tool, not part of the automated suite — proves
 * the CHIP `success_callback` webhook path end to end against the real
 * CHIP API, the one half `app:chip-smoke-test` (createPayment/getPayment
 * only) and ChipGatewayTest's Http::fake() coverage can't:
 *
 *   createPayment (real, success_callback in the payload)
 *     -> POST /purchases/{id}/mark_as_paid/  (CHIP fires the callback)
 *     -> CHIP POSTs a signed Purchase to CHIP_CALLBACK_URL
 *     -> your public tunnel -> POST /api/webhooks/chip
 *     -> verifyWebhookSignature() (real GET /public_key/)
 *     -> Order flips to Paid -> FulfillOrderJob -> supplier delivery
 *
 * Needs CHIP_CALLBACK_URL pointed at a PUBLIC url (a cloudflared tunnel
 * to this backend), a test-mode CHIP key, a running queue worker for
 * the delivery half, and at least one active supplier-linked Package.
 * Creates a real Order row (deleted with --cleanup). Refuses to run
 * with APP_ENV=production.
 */
#[Signature('app:chip-webhook-smoke-test {--package= : Package id to order (default: cheapest active supplier-linked one)} {--cleanup : Delete the test Order when done} {--wait=60 : Seconds to poll for the webhook}')]
#[Description('End-to-end CHIP success_callback webhook verification against the real CHIP API. Non-production only.')]
class ChipWebhookSmokeTest extends Command
{
    public function handle(PaymentGatewayFactory $gateways, ChipCredentialResolver $credentialResolver): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run with APP_ENV=production — this creates a real Order and calls mark_as_paid.');

            return self::FAILURE;
        }

        // ADR-110 PR-C — resolves identically to `$gateways->make('chip')`
        // below (the same `payment-gateway.chip` container binding);
        // only the raw `mark_as_paid` HTTP call further down needs its
        // own copy of the credentials, since it bypasses `ChipGateway`
        // entirely. See `ChipCredentialResolver`'s own doc comment.
        $credentials = $credentialResolver->resolve();

        if (blank($credentials['secret_key']) || blank($credentials['brand_id'])) {
            $this->error('No CHIP secret_key/brand_id configured — set them via /middleware/payment-gateways or CHIP_SECRET_KEY/CHIP_BRAND_ID in .env — nothing to test.');

            return self::FAILURE;
        }

        $config = config('services.chip');
        $callbackUrl = (string) ($config['callback_url'] ?? '');

        if ($this->looksLocal($callbackUrl)) {
            $this->error("CHIP_CALLBACK_URL resolves to a non-public host ({$callbackUrl}).");
            $this->line('CHIP cannot reach it. Start a tunnel and set CHIP_CALLBACK_URL, e.g.:');
            $this->line('  cloudflared tunnel --url https://kedairuncit-backend.test');
            $this->line('  CHIP_CALLBACK_URL=https://<name>.trycloudflare.com/api/webhooks/chip');

            return self::FAILURE;
        }

        $package = $this->resolvePackage();

        if ($package === null) {
            $this->error('No active supplier-linked Package found. Promote one first, or pass --package=<id>.');

            return self::FAILURE;
        }

        $this->warn('CHIP has no separate sandbox endpoint — this hits the real CHIP API with whatever key is in .env. Confirm it is a TEST-mode key.');
        $this->line("Callback URL CHIP will be told: <info>{$callbackUrl}</info>");
        $this->line("Ordering package #{$package->id} \"{$package->name}\" — {$this->ringgit($package->standard_selling_price)}");
        $this->newLine();

        $gateway = $gateways->make('chip');
        $reference = 'WHSMOKE-'.now()->format('YmdHis');

        $this->info('1/4  createPayment (success_callback goes in the payload from config)...');

        $created = $gateway->createPayment(new PaymentRequest(
            referenceId: $reference,
            amountSen: $package->standard_selling_price,
            currency: 'MYR',
            country: 'MY',
            channelCode: 'fpx',
            channelProperties: [
                'success_return_url' => 'https://example.com/checkout/success',
                'failure_return_url' => 'https://example.com/checkout/failure',
            ],
            description: "Webhook smoke {$reference}",
            customer: new PaymentCustomer(
                referenceId: $reference,
                givenNames: 'Webhook Smoke',
                email: 'webhook-smoke@example.com',
            ),
        ));

        if (! $created->success) {
            $this->error("createPayment failed: {$created->errorMessage}");

            return self::FAILURE;
        }

        $purchaseId = $created->data['payment_request_id'];
        $this->line("     purchase id: <info>{$purchaseId}</info>");

        $order = Order::query()->create([
            'order_number' => $reference,
            'affiliate_id' => Affiliate::primary()->id,
            'customer_email' => 'webhook-smoke@example.com',
            'customer_name' => 'Webhook Smoke',
            'player_id' => '51049607',
            'server_id' => '2005',
            'game_id' => $package->game_id,
            'package_id' => $package->id,
            'supplier_id' => $package->supplier_id,
            'supplier_product_ref' => $package->supplier_package_ref,
            'cost_price' => $package->cost_price,
            'standard_selling_price' => $package->standard_selling_price,
            'selling_price' => $package->standard_selling_price,
            'transaction_fee' => 0,
            'final_amount' => $package->standard_selling_price,
            'platform_profit' => $package->standard_selling_price - $package->cost_price,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
            'payment_gateway' => 'chip',
            'channel_code' => 'fpx',
            'payment_ref' => $purchaseId,
        ]);

        $this->line("     order: <info>{$order->order_number}</info> (id {$order->id}), payment_status=pending");
        $this->newLine();

        $this->info('2/4  POST /purchases/{id}/mark_as_paid/ — CHIP fires the callback...');

        $marked = Http::withToken($credentials['secret_key'])
            ->acceptJson()
            ->post(rtrim($credentials['base_url'], '/')."/purchases/{$purchaseId}/mark_as_paid/");

        if ($marked->failed()) {
            $this->error("mark_as_paid failed (HTTP {$marked->status()}): {$marked->body()}");
            $this->line('Fall back to paying the purchase on its hosted checkout page:');
            $this->line('  '.($created->data['actions'][0]['value'] ?? '(no checkout_url returned)'));

            return $this->finish($order, false);
        }

        $this->line('     marked paid CHIP-side. Waiting for the callback to land...');
        $this->newLine();

        $this->info('3/4  polling the Order for the webhook result...');

        $deadline = now()->addSeconds((int) $this->option('wait'));
        $paidSeen = false;

        while (now()->lt($deadline)) {
            $order->refresh();

            if (! $paidSeen && $order->payment_status === PaymentStatus::Paid) {
                $paidSeen = true;
                $this->line('     <info>payment_status = Paid</info> — signed callback verified + parsed + applied.');
            }

            if ($order->delivery_status === DeliveryStatus::Delivered) {
                $this->line("     <info>delivery_status = Delivered</info> — supplier_ref {$order->supplier_ref}");
                break;
            }

            if (in_array($order->delivery_status, [DeliveryStatus::Failed, DeliveryStatus::NeedsReview], true)) {
                $this->line("     delivery_status = {$order->delivery_status->value} (fulfillment ran; supplier side needs attention — fine for this test)");
                break;
            }

            sleep(2);
        }

        $order->refresh();
        $this->newLine();
        $this->info('4/4  verdict');

        $webhookOk = $order->payment_status === PaymentStatus::Paid;

        if ($webhookOk) {
            $this->line('  ✅ CHIP success_callback verified end to end — signature, parse, Order transition.');
        } else {
            $this->line('  ❌ Order never went Paid. Check: tunnel reachable? CHIP_CALLBACK_URL correct? backend logs for "Rejected CHIP webhook".');
        }

        $this->line("  delivery_status: {$order->delivery_status->value}"
            .($order->delivery_status === DeliveryStatus::NotStarted ? '  (is a queue worker running? FulfillOrderJob needs one)' : ''));
        $this->line("  inspect: /admin/orders → {$order->order_number}");

        return $this->finish($order, $webhookOk);
    }

    private function finish(Order $order, bool $ok): int
    {
        if ($this->option('cleanup')) {
            $order->delete();
            $this->line("  cleaned up test Order {$order->order_number}.");
        } else {
            $this->line('  (left the test Order in place — re-run with --cleanup to delete it)');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function resolvePackage(): ?Package
    {
        if ($id = $this->option('package')) {
            return Package::query()->find($id);
        }

        return Package::query()
            ->where('is_active', true)
            ->whereNotNull('supplier_id')
            ->whereNotNull('supplier_package_ref')
            ->orderBy('standard_selling_price')
            ->first();
    }

    private function looksLocal(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        return $host === ''
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost')
            || $host === 'localhost'
            || str_starts_with($host, '127.')
            || str_starts_with($host, '0.0.0.0');
    }

    private function ringgit(int $sen): string
    {
        return 'RM '.number_format($sen / 100, 2);
    }
}
