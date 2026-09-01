<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reseller\UpdateResellerProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-059 59c: the reseller Profile screen. Company identity
 * (`business_name`, `email`) is admin-controlled and read-only here;
 * the reseller edits only its payout bank details, which prefill the
 * Withdrawal request form (founder decision 2026-08-31).
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $reseller = $request->user()->reseller;

        return response()->json([
            'business_name' => $reseller->business_name,
            'contact_name' => $reseller->contact_name,
            'email' => $reseller->email,
            'phone' => $reseller->phone,
            'bank_name' => $reseller->bank_name,
            'bank_account_no' => $reseller->bank_account_no,
            'bank_account_holder' => $reseller->bank_account_holder,
        ]);
    }

    public function update(UpdateResellerProfileRequest $request): JsonResponse
    {
        $reseller = $request->user()->reseller;
        $reseller->update($request->validated());

        return $this->show($request);
    }
}
