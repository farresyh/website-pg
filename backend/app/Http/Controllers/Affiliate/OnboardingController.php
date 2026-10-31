<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Services\Partner\PartnerReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-133 decision 14: the affiliate's own checklist, read-only. */
class OnboardingController extends Controller
{
    public function __construct(private readonly PartnerReadinessService $readiness) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->readiness->forAffiliate($request->user()->affiliateOwner())->toArray());
    }
}
