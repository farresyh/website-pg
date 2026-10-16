<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlayerCheck\CheckPlayerRequest;
use App\Services\PlayerCheck\PublicPlayerCheckService;
use App\Services\PlayerCheck\TurnstileVerifier;
use App\Support\StorefrontBrand;
use Illuminate\Http\JsonResponse;

/**
 * The public Game ID Checker (ADR-123). Guest-callable like the catalog.
 * Responses carry our wording only: a store, a zone label, a nickname,
 * never the provider's name, raw country or message.
 */
class PlayerCheckController extends Controller
{
    public function __construct(
        private readonly PublicPlayerCheckService $checks,
        private readonly StorefrontBrand $storefrontBrand,
    ) {}

    public function families(): JsonResponse
    {
        $brand = $this->storefrontBrand->get();

        return response()->json([
            // ADR-123 decision 10: only the primary brand's pages are indexed.
            'indexable' => (bool) $brand->is_primary,
            'families' => $this->checks->families($brand),
        ]);
    }

    public function family(string $slug): JsonResponse
    {
        $brand = $this->storefrontBrand->get();
        $family = $this->checks->findFamily($brand, $slug);

        abort_if($family === null, 404);

        return response()->json(['indexable' => (bool) $brand->is_primary, 'family' => $family]);
    }

    public function check(CheckPlayerRequest $request, TurnstileVerifier $turnstile, string $slug): JsonResponse
    {
        if (! $turnstile->isConfigured()) {
            return response()->json(['message' => 'The Game ID Checker is not available right now.', 'reason' => 'checker_unavailable'], 503);
        }

        $data = $request->validated();

        if (! $turnstile->verify($data['turnstile_token'], $request->ip())) {
            return response()->json(['message' => 'Please complete the verification and try again.', 'reason' => 'turnstile_failed'], 422);
        }

        return response()->json(
            $this->checks->check($this->storefrontBrand->get(), $slug, $data['player_id'], $data['server_id'] ?? null),
        );
    }
}
