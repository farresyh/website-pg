<?php

namespace App\Http\Controllers\ResellerApi;

use App\Services\Reseller\ResellerWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-074 decision 3: `GET /api/reseller/v1/balance` — the caller's own wallet balance. */
class BalanceController extends Controller
{
    public function __construct(private readonly ResellerWalletService $wallet) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['balance_sen' => $this->wallet->balance($this->reseller($request))]);
    }
}
