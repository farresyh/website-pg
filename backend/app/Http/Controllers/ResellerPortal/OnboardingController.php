<?php

namespace App\Http\Controllers\ResellerPortal;

use App\Services\Partner\PartnerReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ADR-133 decision 14: the reseller's own checklist, read-only. */
class OnboardingController extends Controller
{
    public function __construct(private readonly PartnerReadinessService $readiness) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->readiness->forReseller($this->reseller($request))->toArray());
    }
}
