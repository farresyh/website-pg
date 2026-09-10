<?php

namespace App\Http\Controllers\ResellerPortal;

use App\Http\Requests\ResellerPortal\StoreApiKeyRequest;
use App\Http\Requests\ResellerPortal\UpdateApiKeyRequest;
use App\Models\ResellerApiKey;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-072 decision 5 / PR-G planning addendum decision 7: full
 * self-service Reseller API key management — reuses
 * `ResellerApiKeyService::issue()`/`revoke()` as-is (ADR-074), the same
 * seam `Admin\ResellerApiKeyController` already uses; this is the
 * portal-facing counterpart, capped at 5 active keys per account. Admin
 * retains the same capability in parallel (support/recovery path), not
 * removed.
 */
class ApiKeyController extends Controller
{
    private const MAX_ACTIVE_KEYS = 5;

    public function __construct(private readonly ResellerApiKeyService $apiKeys) {}

    public function index(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        return response()->json(
            $reseller->apiKeys()->orderByDesc('created_at')->get()->map(fn (ResellerApiKey $key) => self::publicKey($key))
        );
    }

    /** The plaintext key is in this response ONLY — never retrievable again. */
    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        $activeCount = $reseller->apiKeys()->whereNull('revoked_at')->count();

        if ($activeCount >= self::MAX_ACTIVE_KEYS) {
            throw ValidationException::withMessages([
                'name' => ['You can have at most '.self::MAX_ACTIVE_KEYS.' active API keys. Revoke one before issuing another.'],
            ]);
        }

        $issued = $this->apiKeys->issue($reseller, $request->validated('name'));

        return response()->json([
            ...self::publicKey($issued['key']),
            'plain_text_key' => $issued['plainText'],
        ], 201);
    }

    /** ADR-084 PR-4: edit this key's IP allowlist (empty array = any IP). */
    public function update(UpdateApiKeyRequest $request, ResellerApiKey $api_key): JsonResponse
    {
        $reseller = $this->reseller($request);

        abort_unless($api_key->reseller_id === $reseller->id, 404);

        $this->apiKeys->setAllowedIps($api_key, $request->validated('allowed_ips'));

        return response()->json(self::publicKey($api_key->fresh()));
    }

    public function destroy(Request $request, ResellerApiKey $api_key): Response
    {
        $reseller = $this->reseller($request);

        abort_unless($api_key->reseller_id === $reseller->id, 404);

        $this->apiKeys->revoke($api_key);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private static function publicKey(ResellerApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'allowed_ips' => $key->allowed_ips ?? [],
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'last_used_ip' => $key->last_used_ip,
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'created_at' => $key->created_at?->toIso8601String(),
        ];
    }
}
