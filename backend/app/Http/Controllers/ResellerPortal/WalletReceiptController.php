<?php

namespace App\Http\Controllers\ResellerPortal;

use App\Services\Reseller\ResellerWalletReceiptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** ADR-131 decision 9: the Payment Receipt PDF for one of the caller's own top-ups. */
class WalletReceiptController extends Controller
{
    public function __construct(private readonly ResellerWalletReceiptService $receipts) {}

    public function show(Request $request, string $number): Response
    {
        $data = $this->receipts->forNumber($this->reseller($request), $number);

        if ($data === null) {
            throw new NotFoundHttpException('Receipt not found.');
        }

        return Pdf::loadView('receipts.purchase-balance', $data)->download($number.'.pdf');
    }
}
