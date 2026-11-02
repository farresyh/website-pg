<?php

namespace App\Http\Controllers\ResellerPortal;

use App\Http\Requests\ResellerPortal\TopupRequest;
use App\Models\Reseller;
use App\Services\Payment\Chip\FpxBankRedirect;
use App\Services\Reseller\InvalidWalletTopupAmountException;
use App\Services\Reseller\NoResellerTierAssignedException;
use App\Services\Reseller\PendingWalletTopupAlreadyExistsException;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\ResellerWalletTopupService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use App\Services\Reseller\WalletTopupCancelOutcome;
use App\Services\Reseller\WalletTopupCheckoutFailedException;
use App\Services\Reseller\WalletTopupEndCause;
use App\Services\Reseller\WalletTopupNotPayableException;
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
            // ADR-131 decision 7: the portal shows these; it keeps no constant of its own.
            'limits' => $this->wallets->limits($reseller),
            // ADR-135 decision 1: a database read; the link comes only from resume.
            'pending_topup' => $this->pendingTopup($reseller),
        ]);
    }

    /** ADR-135 decision 2: the stored link, verified with CHIP first. */
    public function resume(Request $request, string $reference): JsonResponse
    {
        try {
            return response()->json(['checkout_url' => $this->topups->resume($this->reseller($request), $reference)]);
        } catch (WalletTopupNotPayableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /** ADR-135 decision 5: decided by CHIP's re-read answer, never locally. */
    public function cancel(Request $request, string $reference): JsonResponse
    {
        $reseller = $this->reseller($request);
        $outcome = $this->topups->cancel($reseller, $reference, WalletTopupEndCause::PortalCancel);

        [$code, $message] = match ($outcome) {
            WalletTopupCancelOutcome::Cancelled => ['cancelled', 'Top-up cancelled. Its payment link can no longer be paid.'],
            WalletTopupCancelOutcome::AlreadyPaid => ['already_paid', 'This top-up is already paid, so it cannot be cancelled. Your balance will show shortly.'],
            WalletTopupCancelOutcome::AlreadyEnded => ['already_ended', 'This top-up has already ended.'],
            WalletTopupCancelOutcome::InFlight => ['in_flight', 'The payment is still being processed. Wait for it to finish, then check your balance.'],
            WalletTopupCancelOutcome::NoPurchaseYet => ['no_purchase_yet', 'This top-up is still being set up and expires by itself.'],
        };

        return response()->json(['outcome' => $code, 'message' => $message], $outcome === WalletTopupCancelOutcome::Cancelled ? 200 : 409);
    }

    /** @return array<string, mixed>|null */
    private function pendingTopup(Reseller $reseller): ?array
    {
        $attempt = $this->topups->currentPending($reseller);

        return $attempt === null ? null : [
            'reference' => $attempt->reference,
            'amount_sen' => $attempt->amount_sen,
            'total_charged_sen' => $attempt->total_charged_sen,
            'channel_code' => $attempt->channel_code,
            'expires_at' => $attempt->expires_at->toIso8601String(),
            'has_link' => $attempt->checkout_url !== null,
        ];
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
        } catch (InvalidWalletTopupAmountException|PendingWalletTopupAlreadyExistsException|NoResellerTierAssignedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (WalletTopupCheckoutFailedException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'reference' => $attempt->reference,
            // ADR-130: the bank applies to this response only; the stored URL stays plain.
            'checkout_url' => $attempt->status === WalletTopupAttemptStatus::Pending
                ? FpxBankRedirect::applyToUrl($attempt->checkout_url, $request->validated('fpx_bank_code'))
                : $attempt->checkout_url,
            'amount_sen' => $attempt->amount_sen,
            'total_charged_sen' => $attempt->total_charged_sen,
            'expires_at' => $attempt->expires_at?->toIso8601String(),
        ], 201);
    }
}
