<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustSupplierTransferRequest;
use App\Http\Requests\Admin\StoreSupplierTransferRequest;
use App\Http\Requests\Admin\VoidSupplierTransferRequest;
use App\Models\Supplier;
use App\Models\SupplierTransfer;
use App\Services\Accounting\SupplierFundingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-083 decision 2 (PR-1): "Record Supplier Transfer" admin UI. Placed
 * under `/admin` (not `/middleware`) — this is a bookkeeping/accounting
 * action, not a supplier-integration one, same reasoning as Membership
 * pricing landing on `/admin` (ADR's own decision 14 precedent). super_admin
 * only, same tier as the rest of this ADR family's screens.
 */
class SupplierTransferController extends Controller
{
    public function __construct(private readonly SupplierFundingService $funding) {}

    public function index(Supplier $supplier): JsonResponse
    {
        return response()->json([
            'ledger_balance' => $supplier->supplierLedgerBalance(),
            'currency' => $supplier->currency,
            'transfers' => $this->funding->transfers($supplier),
        ]);
    }

    public function store(StoreSupplierTransferRequest $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validated();

        $transfer = $this->funding->recordTransfer(
            $supplier,
            $data['source_channel'],
            $data['amount_myr_sent'],
            $data['fee_myr'] ?? 0,
            $data['currency'],
            (string) $data['amount_foreign_received'],
            isset($data['supplier_fee']) ? (string) $data['supplier_fee'] : null,
            $data['reference_no'] ?? null,
            $request->file('receipt'),
            $request->user()->id,
        );

        Log::info('Supplier transfer recorded', [
            'supplier_id' => $supplier->id,
            'supplier_transfer_id' => $transfer->id,
            'amount_myr_sent' => $transfer->amount_myr_sent,
            'currency' => $transfer->currency,
            'amount_foreign_received' => $transfer->amount_foreign_received,
            'supplier_fee' => $transfer->supplier_fee,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'transfer' => $transfer,
            'ledger_balance' => $supplier->refresh()->supplierLedgerBalance(),
        ], 201);
    }

    public function downloadReceipt(SupplierTransfer $supplierTransfer): StreamedResponse
    {
        return $this->funding->downloadReceipt($supplierTransfer);
    }

    /**
     * ADR-083 2026-09-15 addendum — "Adjust": a partial, signed
     * correction against an already-recorded transfer. See
     * `SupplierFundingService::recordManualAdjustment()`.
     */
    public function adjust(AdjustSupplierTransferRequest $request, SupplierTransfer $supplierTransfer): JsonResponse
    {
        $this->assertNotVoided($supplierTransfer);

        $entry = $this->funding->recordManualAdjustment(
            $supplierTransfer,
            (string) $request->validated('amount'),
            $request->validated('reason'),
            $request->user()->id,
        );

        Log::info('Supplier transfer manually adjusted', [
            'supplier_transfer_id' => $supplierTransfer->id,
            'amount' => $entry->amount,
            'reason' => $entry->reason,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'entry' => $entry,
            'ledger_balance' => $supplierTransfer->supplier->refresh()->supplierLedgerBalance(),
        ], 201);
    }

    /**
     * ADR-083 2026-09-15 addendum — "Void Entirely": the money behind
     * this transfer never reached the supplier at all. See
     * `SupplierFundingService::voidTransfer()`.
     */
    public function void(VoidSupplierTransferRequest $request, SupplierTransfer $supplierTransfer): JsonResponse
    {
        $this->assertNotVoided($supplierTransfer);

        $entry = $this->funding->voidTransfer(
            $supplierTransfer,
            $request->validated('reason'),
            $request->user()->id,
        );

        Log::warning('Supplier transfer voided', [
            'supplier_transfer_id' => $supplierTransfer->id,
            'reversed_amount' => $entry->amount,
            'reason' => $entry->reason,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'transfer' => $supplierTransfer->fresh(),
            'entry' => $entry,
            'ledger_balance' => $supplierTransfer->supplier->refresh()->supplierLedgerBalance(),
        ], 201);
    }

    /** Shared guard for both correction actions — a voided transfer is already fully reversed, a second correction on it would be a real double-count. */
    private function assertNotVoided(SupplierTransfer $transfer): void
    {
        if ($transfer->voided_at !== null) {
            throw ValidationException::withMessages([
                'transfer' => ['This transfer has already been voided.'],
            ]);
        }
    }
}
