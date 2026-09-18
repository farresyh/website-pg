<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Requests\Middleware\UpdatePaymentGatewayRequest;
use App\Models\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

/**
 * ADR-110 PR-C — CHIP credential `.env`→DB migration. Deliberately
 * narrower than `SupplierController`: `payment_gateways` has no
 * create/delete (a gateway's `gateway_key` set is fixed by what
 * `PaymentGatewayFactory` supports, `chip` the only one today — ADR-022's
 * still-open seam for a future 2nd gateway), and no per-slug field
 * schema registry (`SupplierConfigSchema`'s equivalent) since only one
 * shape exists — `secret_key` is the one field masked as secret.
 */
class PaymentGatewayController extends Controller
{
    private const SECRET_KEYS = ['secret_key'];

    public function index(): JsonResponse
    {
        $gateways = PaymentGateway::query()->orderBy('gateway_key')->get()
            ->map(fn (PaymentGateway $gateway) => $this->present($gateway))
            ->values();

        return response()->json($gateways);
    }

    /**
     * Upsert semantics — `payment_gateways` starts empty (no seeded
     * rows), so the founder's first save for a given `gatewayKey`
     * creates the row; every save after that merges onto the existing
     * `api_config` rather than replacing it (a blank/omitted secret
     * field must never be read as "clear it", same ADR-046 decision 3
     * discipline `SupplierController::update()` already follows).
     */
    public function update(UpdatePaymentGatewayRequest $request, string $gatewayKey): JsonResponse
    {
        $gateway = PaymentGateway::query()->firstOrNew(['gateway_key' => $gatewayKey]);

        $gateway->api_config = array_merge($gateway->api_config ?? [], $request->validated('api_config'));
        $gateway->save();

        $payload = $this->present($gateway);
        $payload['connection_probe'] = $this->probeConnection($gateway);

        return response()->json($payload);
    }

    /**
     * A cheap authenticated call (`GET /public_key/`, same endpoint
     * `ChipGateway::fetchPublicKey()` already uses for webhook
     * verification) against the config just saved — never against the
     * live `ChipGateway` container binding, which still resolves the
     * old credential until this platform's own binding is cut over.
     */
    private function probeConnection(PaymentGateway $gateway): array
    {
        $config = $gateway->api_config ?? [];
        $baseUrl = $config['base_url'] ?? config('services.chip.base_url');
        $secretKey = $config['secret_key'] ?? null;

        if (empty($secretKey)) {
            return ['connection_ok' => false, 'error' => 'No secret_key configured.'];
        }

        $response = Http::baseUrl($baseUrl)->withToken($secretKey)->acceptJson()->timeout(10)->get('/public_key/');

        return [
            'connection_ok' => $response->successful(),
            'error' => $response->successful() ? null : "HTTP {$response->status()}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PaymentGateway $gateway): array
    {
        $config = $gateway->api_config ?? [];

        $visible = collect($config)
            ->reject(fn ($value, string $key) => in_array($key, self::SECRET_KEYS, true))
            ->all();

        $configuredSecretKeys = collect(self::SECRET_KEYS)
            ->filter(fn (string $key) => ! empty($config[$key] ?? null))
            ->values()
            ->all();

        return [
            'gateway_key' => $gateway->gateway_key,
            'has_credentials' => $config !== [],
            'configured_secret_keys' => $configuredSecretKeys,
            'visible_config' => $visible,
            'updated_at' => $gateway->updated_at?->toISOString(),
        ];
    }
}
