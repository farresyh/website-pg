<?php

namespace App\Services\Reseller;

use App\Models\LedgerEntry;
use App\Models\Reseller;
use App\Models\WalletTopupReceipt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
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
 * PR-C scope only: `manualCredit()` (decision 3b — admin-mediated
 * top-up, the only funding path that exists before a `Reseller` has any
 * identity of its own to self-serve with, see this ADR's own build
 * addendum). The self-serve CHIP top-up (decision 3a) is deferred to
 * build alongside whichever PR first gives a `Reseller` an entry point
 * to trigger it from (PR-G's portal Wallet screen, most likely) — this
 * service's `manualCredit()` already writes the same `wallet_topup`
 * ledger type that path will reuse, so nothing here needs reshaping when
 * it lands. `wallet_debit` (PR-D) and `wallet_refund` (PR-D's
 * retry-then-refund terminal action) are the other two decision-2 entry
 * types — neither is written from this service yet.
 */
final class ResellerWalletService
{
    public function __construct(private readonly LedgerService $ledger) {}

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
}
