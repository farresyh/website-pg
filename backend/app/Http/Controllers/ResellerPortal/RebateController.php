<?php

namespace App\Http\Controllers\ResellerPortal;

use App\Http\Requests\ResellerPortal\ClaimRebateRequest;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Rebate\RebateClaimService;
use App\Services\Rebate\RebatePortalView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-139 decisions 16, 41 (PR 4a): the reseller's Rebate page and its
 * claim. Portal only, through a human login: never the Reseller API or the
 * bot (decision 16).
 */
class RebateController extends Controller
{
    public function show(Request $request, RebatePortalView $view): JsonResponse
    {
        return response()->json($view->forReseller($this->reseller($request)));
    }

    public function claim(ClaimRebateRequest $request, RebateClaimService $claims, LedgerService $ledger): JsonResponse
    {
        $reseller = $this->reseller($request);
        $row = $claims->claim(
            $reseller,
            $request->user(),
            (int) $request->validated('year'),
            (int) $request->validated('month'),
            (int) $request->validated('step'),
            $request->boolean('accept_terms'),
        );

        return response()->json([
            'step' => $row->step,
            'amount_sen' => $row->amount_sen,
            'adjusted' => $row->adjusted,
            'balance_sen' => $ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id),
        ]);
    }
}
