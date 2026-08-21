<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PaymentMethodCatalogController;
use App\Http\Requests\Middleware\UpdatePaymentMethodFeeRequest;
use App\Http\Requests\Middleware\UpdatePaymentMethodStatusRequest;
use App\Models\PaymentMethod;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SET-7/SET-11 — see the create_payment_methods_table migration's doc
 * comment for the full "why /middleware, why admin-curated not synced"
 * reasoning. Lives under Middleware (not Admin\Settings) per founder
 * decision, 2026-07-25 — grouped with other external-integration-health
 * tooling rather than general platform settings.
 */
class PaymentMethodController extends Controller
{
    /**
     * ADR-014: same 60s-TTL, invalidate-on-write policy as
     * GameController — see that class's own doc comment.
     */
    private const CACHE_TTL_SECONDS = 60;

    private const CACHE_KEY = 'catalog.payment_methods.index';

    /**
     * Only the unfiltered listing (no category) is cached — see
     * GameController::index()'s identical reasoning.
     */
    public function index(Request $request): JsonResponse
    {
        $category = $request->query('category');

        if ($category === null) {
            return response()->json(Cache::remember(
                self::CACHE_KEY,
                self::CACHE_TTL_SECONDS,
                // ->toArray(), not the raw Collection — this app's
                // `database` cache store corrupts a cached value that
                // still has real objects nested inside it on the next
                // read (found live, 2026-07-26 — see GameController's/
                // CatalogController's doc comments for the full story;
                // this exact same bug was found here too during a docs
                // accuracy pass, not caught in the original sweep).
                fn () => PaymentMethod::query()->orderBy('category')->orderBy('label')->get()->toArray(),
            ));
        }

        return response()->json(
            PaymentMethod::query()->where('category', $category)->orderBy('category')->orderBy('label')->get(),
        );
    }

    /**
     * Inline on/off toggle, same pattern as PackageController's own
     * updateStatus — admin flips this only after confirming the
     * channel actually works (own Dashboard or the test() action
     * below), never on by default (the migration seeds is_active=false).
     */
    public function updateStatus(UpdatePaymentMethodStatusRequest $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $isActive = $request->validated('is_active');

        if ($isActive) {
            $this->assertMethodKeyNotActiveElsewhere($paymentMethod);
        }

        $paymentMethod->update(['is_active' => $isActive]);
        Cache::forget(self::CACHE_KEY);
        // Only this action changes what the public listing shows
        // (label/channel_code/category never change here) — updateFee()
        // and test() don't touch is_active, so they don't need to
        // invalidate the public cache too.
        PaymentMethodCatalogController::forgetCache();

        return response()->json($paymentMethod);
    }

    /**
     * ADR-022's newest addendum, decision 4: at most one row per
     * `method_key` (the real-world payment method, independent of
     * gateway) may be active at a time — otherwise a customer would
     * see two visually-identical buttons for the same method routed
     * through two different processors, unable to tell which is which.
     * A null `method_key` opts a row out of this check entirely.
     */
    private function assertMethodKeyNotActiveElsewhere(PaymentMethod $paymentMethod): void
    {
        if ($paymentMethod->method_key === null) {
            return;
        }

        $conflict = PaymentMethod::query()
            ->where('method_key', $paymentMethod->method_key)
            ->where('id', '!=', $paymentMethod->id)
            ->where('is_active', true)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'is_active' => ['Another gateway is already active for this payment method. Deactivate it first.'],
            ]);
        }
    }

    public function updateFee(UpdatePaymentMethodFeeRequest $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->update($request->validated());
        Cache::forget(self::CACHE_KEY);

        return response()->json($paymentMethod);
    }

    /**
     * Fires a real, harmless payment request for this specific channel
     * (harmless in the sense PAY-4 already establishes: no card data
     * touches us, and a created-but-never-completed payment request
     * moves no money) — the same manual curl-based check done during
     * the 2026-07-25 live e2e checkout session, now a proper admin
     * tool. Records the exact outcome so admin doesn't need direct
     * Xendit Dashboard access to know why a channel is rejected.
     *
     * Sends a nominal `customer` (PaymentCustomer) and default
     * `success_return_url`/`failure_return_url` — this is a technical
     * channel probe, not a real checkout, so there's no real customer
     * name/email to use. Admin can still override `channel_properties`
     * per request (e.g. CARDS needs `card_details`, which no default
     * here can supply).
     */
    public function test(Request $request, PaymentMethod $paymentMethod, PaymentGatewayFactory $gatewayFactory): JsonResponse
    {
        $gateway = $gatewayFactory->make($paymentMethod->gateway);

        $channelProperties = array_merge([
            'success_return_url' => url('/'),
            'failure_return_url' => url('/'),
        ], $request->input('channel_properties', []));

        $response = $gateway->createPayment(new PaymentRequest(
            referenceId: 'channel-test-'.Str::ulid(),
            amountSen: 100,
            currency: 'MYR',
            country: 'MY',
            channelCode: $paymentMethod->channel_code,
            channelProperties: $channelProperties,
            description: "Payment Methods channel test: {$paymentMethod->channel_code}",
            customer: new PaymentCustomer(
                referenceId: 'channel-test-customer-'.Str::ulid(),
                givenNames: 'Test Customer',
            ),
        ));

        $paymentMethod->update([
            'last_tested_at' => now(),
            'last_test_result' => $response->success
                ? 'success'
                : "failed: [{$response->errorCode}] {$response->errorMessage}",
        ]);
        Cache::forget(self::CACHE_KEY);

        return response()->json($paymentMethod->fresh());
    }
}
