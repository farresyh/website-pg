<?php

namespace App\Http\Controllers\ResellerPortal;

use App\Http\Requests\ResellerPortal\TopupRequest;
use App\Services\Reseller\InvalidWalletTopupAmountException;
use App\Services\Reseller\PendingWalletTopupAlreadyExistsException;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\ResellerWalletTopupService;
use App\Services\Reseller\WalletTopupCheckoutFailedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-072 decision 5 / PR-G: the Reseller (wallet) portal's Wallet
 * screen — balance + ledger history (reusing `ResellerWalletService`
 * as-is, just gated by `account.type:reseller` instead of admin) plus
 * the self-serve CHIP top-up trigger (ADR-073 decision 3(a)).
 */
class WalletController extends Controller
{
    public function __construct(
        private readonly ResellerWalletService $wallets,
        private readonly ResellerWalletTopupService $topups,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        return response()->json([
            'balance' => $this->wallets->balance($reseller),
            'entries' => $this->wallets->ledgerEntries($reseller),
        ]);
    }

    public function topup(TopupRequest $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        try {
            $attempt = $this->topups->initiate(
                $reseller,
                (int) $request->validated('amount_sen'),
                $request->validated('channel_code'),
                $request->validated('channel_properties') ?? [],
            );
        } catch (InvalidWalletTopupAmountException|PendingWalletTopupAlreadyExistsException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (WalletTopupCheckoutFailedException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'reference' => $attempt->reference,
            'checkout_url' => $attempt->checkout_url,
            'amount_sen' => $attempt->amount_sen,
            'total_charged_sen' => $attempt->total_charged_sen,
            'expires_at' => $attempt->expires_at?->toIso8601String(),
        ], 201);
    }
}
