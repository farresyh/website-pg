<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliate\UpdateAffiliateProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $affiliate = $request->user()->affiliate;

        return response()->json([
            'business_name' => $affiliate->business_name,
            'contact_name' => $affiliate->contact_name,
            'email' => $affiliate->email,
            'phone' => $affiliate->phone,
            'bank_name' => $affiliate->bank_name,
            'bank_account_no' => $affiliate->bank_account_no,
            'bank_account_holder' => $affiliate->bank_account_holder,
        ]);
    }

    public function update(UpdateAffiliateProfileRequest $request): JsonResponse
    {
        $affiliate = $request->user()->affiliate;
        $affiliate->update($request->validated());

        return $this->show($request);
    }
}
