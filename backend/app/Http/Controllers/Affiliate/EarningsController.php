<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Services\Affiliate\AffiliateEarningsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-059 decision 2/5: the Earnings / Ledger screen — every
 * `ledger_entries` row for this affiliate (margin credits, withdrawal
 * debits, `affiliate_tier_fee` debits), newest first, plus the current
 * withdrawable balance. All reads via AffiliateEarningsService — never
 * raw Eloquent on `LedgerEntry` (it has no `affiliate_id` scope).
 */
class EarningsController extends Controller
{
    public function __construct(private readonly AffiliateEarningsService $earnings) {}

    public function index(Request $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();

        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        return response()->json([
            'balance' => $this->earnings->balance($affiliate),
            'entries' => $this->earnings->ledgerEntries($affiliate, $perPage),
        ]);
    }
}
