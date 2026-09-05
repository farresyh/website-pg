<?php

namespace App\Services\Reseller;

use App\Models\LedgerEntry;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Models\WalletTopupReceipt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\Bot\ResellerBotWalletTopupNotifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-073: the ONE seam for every `Reseller` (wallet) money read/write —
 * mirrors `AffiliateEarningsService`'s own role for `Affiliate`.
 * `ledger_entries` has no `reseller_id` column; every read here filters
 * `owner_type`/`owner_id` explicitly against the `Reseller` passed in.
 *
 * PR-C shipped `manualCredit()` (decision 3b — admin-mediated top-up).
 * PR-G adds `completeTopup()` (decision 3a — the self-serve CHIP path's
 * own webhook-driven credit, `ChipWebhookController`'s third fallback
 * branch) — both write the same `wallet_topup` ledger type, so reporting
 * never has a gap regardless of path. `wallet_debit` (PR-D) and
 * `wallet_refund` (PR-D's retry-then-refund terminal action) are the
 * other two decision-2 entry types — neither is written from this
 * service.
 */
final class ResellerWalletService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ResellerBotWalletTopupNotifier $botNotifier,
    ) {}

    public function balance(Reseller $reseller): int
    {
        return $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id);
    }

    /**
     * The reseller's own wallet ledger history, newest first — same
     * shape as `AffiliateEarningsService::ledgerEntries()`.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function ledgerEntries(Reseller $reseller, int $perPage = 20): LengthAwarePaginator
    {
        $page = LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->where('owner_id', $reseller->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        // Batch-load receipt filenames for this page's rows rather than
        // one query per row — only entries with a receipt attached
        // (reference_type = 'wallet_topup_receipt') need one.
        $receiptIds = $page->getCollection()
            ->where('reference_type', 'wallet_topup_receipt')
            ->pluck('reference_id')
            ->filter()
            ->all();
        $receiptNames = $receiptIds === []
            ? []
            : WalletTopupReceipt::query()->whereIn('id', $receiptIds)->pluck('original_name', 'id');

        return $page->through(fn (LedgerEntry $entry): array => [
            'id' => $entry->id,
            'type' => $entry->type,
            'amount' => $entry->amount,
            'reference_type' => $entry->reference_type,
            'reference_id' => $entry->reference_id,
            'receipt_name' => $entry->reference_type === 'wallet_topup_receipt'
                ? ($receiptNames[$entry->reference_id] ?? null)
                : null,
            'reason' => $entry->reason,
            'created_at' => $entry->created_at?->toIso8601String(),
        ]);
    }

    /**
     * ADR-073 decision 3(b): admin manual-credit. Stores the optional
     * receipt file first (if any), then the ledger credit references it
     * — one transaction, so a failed upload never leaves an orphan
     * ledger entry and vice versa.
     */
    public function manualCredit(
        Reseller $reseller,
        int $amountSen,
        ?string $note,
        ?UploadedFile $receipt,
        int $adminUserId,
    ): LedgerEntry {
        return DB::transaction(function () use ($reseller, $amountSen, $note, $receipt, $adminUserId) {
            $receiptRow = null;

            if ($receipt !== null) {
                $disk = config('filesystems.wallet_receipts_disk');
                $path = $receipt->store('wallet-topup-receipts', $disk);

                $receiptRow = WalletTopupReceipt::query()->create([
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $receipt->getClientOriginalName(),
                    'mime_type' => $receipt->getMimeType(),
                    'size_bytes' => $receipt->getSize(),
                    'uploaded_by' => $adminUserId,
                ]);
            }

            return $this->ledger->credit(
                LedgerOwnerType::ResellerWallet,
                $reseller->id,
                $amountSen,
                'wallet_topup',
                referenceType: $receiptRow !== null ? 'wallet_topup_receipt' : null,
                referenceId: $receiptRow?->id,
                createdBy: $adminUserId,
                reason: $note,
            );
        });
    }

    /** Streams the receipt file back — super_admin only, enforced at the route. */
    public function download(WalletTopupReceipt $receipt)
    {
        return Storage::disk($receipt->disk)->download($receipt->path, $receipt->original_name);
    }

    /**
     * ADR-073 decision 3(a) / PR-G planning addendum decision 11: a
     * paid `WalletTopupAttempt` credits the wallet exactly once — the
     * `wallet_topup` ledger entry references the attempt itself
     * (`reference_type = 'wallet_topup_attempt'`), and repeating this
     * call for an already-`paid` attempt is a clean no-op, same posture
     * `MembershipSubscriptionService::completePaidAttempt()` and the
     * Order webhook branch already take against a duplicate CHIP
     * delivery.
     */
    public function completeTopup(WalletTopupAttempt $attempt): void
    {
        if ($attempt->status === WalletTopupAttemptStatus::Paid) {
            return;
        }

        DB::transaction(function () use ($attempt) {
            $this->ledger->credit(
                LedgerOwnerType::ResellerWallet,
                $attempt->reseller_id,
                $attempt->amount_sen,
                'wallet_topup',
                referenceType: 'wallet_topup_attempt',
                referenceId: $attempt->id,
            );

            $attempt->update(['status' => WalletTopupAttemptStatus::Paid->value]);
        });

        // ADR-076 PR-H decision 2 — a paid top-up fires no event, so the
        // "top-up berjaya" WhatsApp reply is sent from here, the one seam
        // both completion paths (CHIP webhook + reconcile backstop)
        // share. A no-op unless this attempt was started via `.topupbaki`
        // (a `reseller_bot_wallet_topups` row exists); `notified_at`
        // guards a webhook redelivery.
        $this->botNotifier->notifyPaid($attempt);
    }
}
