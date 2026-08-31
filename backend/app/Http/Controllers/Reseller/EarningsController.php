<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Services\Reseller\ResellerEarningsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-059 decision 2/5: the Earnings / Ledger screen — every
 * `ledger_entries` row for this reseller (margin credits, withdrawal
 * debits, `reseller_tier_fee` debits), newest first, plus the current
 * withdrawable balance. All reads via ResellerEarningsService — never
 * raw Eloquent on `LedgerEntry` (it has no `reseller_id` scope).
 */
class EarningsController extends Controller
{
    public function __construct(private readonly ResellerEarningsService $earnings) {}

    public function index(Request $request): JsonResponse
    {
        $reseller = $request->user()->reseller;

        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        return response()->json([
            'balance' => $this->earnings->balance($reseller),
            'entries' => $this->earnings->ledgerEntries($reseller, $perPage),
        ]);
    }
}
