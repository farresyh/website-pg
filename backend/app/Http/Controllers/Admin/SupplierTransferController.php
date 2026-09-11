<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSupplierTransferRequest;
use App\Models\Supplier;
use App\Models\SupplierTransfer;
use App\Services\Accounting\SupplierFundingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
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
}
