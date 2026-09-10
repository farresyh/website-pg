<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreResellerApiKeyRequest;
use App\Http\Requests\Admin\UpdateResellerApiKeyRequest;
use App\Models\Reseller;
use App\Models\ResellerApiKey;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-074 decision 1: admin issues/revokes a `Reseller`'s API keys.
 * super_admin only, same tier as the rest of `/admin/resellers*`
 * (`ResellerController`/`ResellerWalletController`).
 */
class ResellerApiKeyController extends Controller
{
    public function __construct(private readonly ResellerApiKeyService $apiKeys) {}

    public function index(Reseller $reseller): JsonResponse
    {
        return response()->json(
            $reseller->apiKeys()->orderByDesc('created_at')->get()->map(fn (ResellerApiKey $key) => self::publicKey($key))
        );
    }

    /** The plaintext key is in this response ONLY — never retrievable again. */
    public function store(StoreResellerApiKeyRequest $request, Reseller $reseller): JsonResponse
    {
        $issued = $this->apiKeys->issue($reseller, $request->validated('name'));

        Log::info('Reseller API key issued', ['reseller_id' => $reseller->id, 'reseller_api_key_id' => $issued['key']->id]);

        return response()->json([
            ...self::publicKey($issued['key']),
            'plain_text_key' => $issued['plainText'],
        ], 201);
    }

    /** ADR-084 PR-4: admin edits this key's IP allowlist for support (empty = any IP). */
    public function update(UpdateResellerApiKeyRequest $request, Reseller $reseller, ResellerApiKey $api_key): JsonResponse
    {
        abort_unless($api_key->reseller_id === $reseller->id, 404);

        $this->apiKeys->setAllowedIps($api_key, $request->validated('allowed_ips'));

        Log::info('Reseller API key IP allowlist updated by admin', ['reseller_id' => $reseller->id, 'reseller_api_key_id' => $api_key->id]);

        return response()->json(self::publicKey($api_key->fresh()));
    }

    public function destroy(Reseller $reseller, ResellerApiKey $apiKey): Response
    {
        abort_unless($apiKey->reseller_id === $reseller->id, 404);

        $this->apiKeys->revoke($apiKey);

        Log::info('Reseller API key revoked', ['reseller_id' => $reseller->id, 'reseller_api_key_id' => $apiKey->id]);

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
