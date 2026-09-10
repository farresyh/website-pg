<?php

namespace App\Http\Controllers\ResellerApi;

use App\Services\Reseller\ResellerWalletService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-074 decision 3: `GET /api/reseller/v1/balance` — the caller's own wallet balance. */
class BalanceController extends Controller
{
    public function __construct(private readonly ResellerWalletService $wallet) {}

    #[Endpoint(
        title: 'Get wallet balance',
        description: 'The calling reseller\'s current prepaid wallet balance, in sen.',
    )]
    #[Response(status: 200, description: 'The current balance.', examples: [['balance_sen' => 1_250_00]])]
    #[Response(status: 401, description: '`MISSING_API_KEY` or `INVALID_API_KEY`.', type: self::ERROR_SHAPE, examples: [self::ERROR_401])]
    #[Response(status: 403, description: '`RESELLER_INACTIVE` or `IP_NOT_ALLOWED`.', type: self::ERROR_SHAPE, examples: [self::ERROR_403])]
    #[Response(status: 429, description: '`RATE_LIMITED` — retry after the `Retry-After` header.', type: self::ERROR_SHAPE, examples: [self::ERROR_429])]
    public function show(Request $request): JsonResponse
    {
        return response()->json(['balance_sen' => (int) $this->wallet->balance($this->reseller($request))]);
    }
}
