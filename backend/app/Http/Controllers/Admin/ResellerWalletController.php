<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreResellerWalletCreditRequest;
use App\Models\Reseller;
use App\Models\WalletTopupReceipt;
use App\Services\Reseller\ResellerWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-073 decision 3(b) (PR-C, re-scoped — see the ADR's own build
 * addendum for why self-serve CHIP top-up is deferred past this PR):
 * admin manual-credit of a `Reseller`'s prepaid wallet. super_admin
 * only, same tier as the rest of `/admin/resellers*`.
 */
class ResellerWalletController extends Controller
{
    public function __construct(private readonly ResellerWalletService $wallet) {}

    public function index(Reseller $reseller): JsonResponse
    {
        return response()->json([
            'balance_sen' => $this->wallet->balance($reseller),
            'entries' => $this->wallet->ledgerEntries($reseller),
        ]);
    }

    public function credit(StoreResellerWalletCreditRequest $request, Reseller $reseller): JsonResponse
    {
        $data = $request->validated();

        $receiptFile = $request->file('receipt');
        $entry = $this->wallet->manualCredit(
            $reseller,
            $data['amount_sen'],
            $data['note'] ?? null,
            $receiptFile,
            $request->user()->id,
        );

        Log::info('Reseller wallet manually credited', [
            'reseller_id' => $reseller->id,
            'amount_sen' => $data['amount_sen'],
            'ledger_entry_id' => $entry->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'balance_sen' => $this->wallet->balance($reseller),
            'entry' => [
                'id' => $entry->id,
                'type' => $entry->type,
                'amount' => $entry->amount,
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
                'receipt_name' => $receiptFile?->getClientOriginalName(),
                'reason' => $entry->reason,
                'created_at' => $entry->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function downloadReceipt(WalletTopupReceipt $walletTopupReceipt): StreamedResponse
    {
        return $this->wallet->download($walletTopupReceipt);
    }
}
