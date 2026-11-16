<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliate\UpdateAffiliateProfileRequest;
use App\Services\Affiliate\AffiliateBankDetailsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * ADR-059 59c: the affiliate Profile screen. Company identity
 * (`business_name`, `email`) is admin-controlled and read-only here;
 * the affiliate edits only its payout bank details, which prefill the
 * Withdrawal request form (founder decision 2026-08-31).
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();

        return response()->json([
            'business_name' => $affiliate->business_name,
            'contact_name' => $affiliate->contact_name,
            'email' => $affiliate->email,
            'phone' => $affiliate->phone,
            'bank_name' => $affiliate->bank_name,
            'bank_account_no' => $affiliate->bank_account_no,
            'bank_account_holder' => $affiliate->bank_account_holder,
            'writable' => $affiliate->isActive(),
        ]);
    }

    /**
     * Contact fields save directly; the bank details go through their one
     * writer, which records and announces the change (ADR-058 2026-11-16
     * addendum). Refused for a deactivated affiliate by `affiliate.writable`.
     */
    public function update(UpdateAffiliateProfileRequest $request, AffiliateBankDetailsService $bank): JsonResponse
    {
        $user = $request->user();
        $affiliate = $user->affiliateOwner();
        $data = $request->validated();

        $affiliate->update(Arr::only($data, ['contact_name', 'phone']));

        if (Arr::has($data, 'bank_name')) {
            $bank->change($affiliate, Arr::only($data, AffiliateBankDetailsService::FIELDS), $user, $user->currentAccessToken()?->getKey());
        }

        return $this->show($request);
    }
}
