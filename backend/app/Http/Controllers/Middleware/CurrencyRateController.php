<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Models\CurrencyRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-033 addendum decision 1/2/4: the Price Sync Center's "FX Rate
 * History" section — every rate CurrencyRateService has ever fetched,
 * across any currency pair, paginated newest-first. Read-only: there
 * is no admin-editable rate (ADR-033's own decision — auto-fetch
 * only), so unlike PendingReactivationController/PendingPriceChangeController
 * this controller has no approve/dismiss-style write action.
 */
class CurrencyRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);

        return response()->json(
            CurrencyRate::query()
                ->orderBy('fetched_at', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($perPage)
                ->withQueryString(),
        );
    }
}
