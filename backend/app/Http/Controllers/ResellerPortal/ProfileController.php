<?php

namespace App\Http\Controllers\ResellerPortal;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-072 decision 5 / PR-G: view-only Profile screen — a Reseller
 * (wallet) portal user views their own business_name/contact/email/
 * phone and assigned tier. Read-only: unlike `Affiliate`'s Profile
 * screen (which edits payout bank details, ADR-059 59c), there's no
 * existing write precedent for this account type to mirror — a
 * Reseller's business details are admin-curated (`/admin/resellers`),
 * consistent with registration itself staying admin-created (PR-G
 * planning addendum decision 1).
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request)->load(['tier' => fn ($q) => $q->withTrashed()]);

        return response()->json([
            'business_name' => $reseller->business_name,
            'contact_name' => $reseller->contact_name,
            'email' => $reseller->email,
            'phone' => $reseller->phone,
            'tier_name' => $reseller->tier?->name,
            'markup_percent' => $reseller->tier?->markup_percent,
            'is_active' => (bool) $reseller->is_active,
        ]);
    }
}
