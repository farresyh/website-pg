<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AskReportAssistantRequest;
use App\Services\ReportAssistant\ReportAssistantService;
use Illuminate\Http\JsonResponse;

/**
 * ADR-087 — its own route under Reports (decision 9), super_admin-only
 * (decision 6, enforced by the route's admin.role middleware). Thin by
 * design: all orchestration lives in ReportAssistantService, all
 * business-rule/denylist correctness lives in the curated views + the
 * SqlGuard the service calls — nothing money-shaped is computed here.
 */
class ReportAssistantController extends Controller
{
    public function __construct(private readonly ReportAssistantService $assistant) {}

    public function ask(AskReportAssistantRequest $request): JsonResponse
    {
        $result = $this->assistant->ask(
            $request->user(),
            $request->string('question')->toString(),
            $request->input('history', []),
        );

        return response()->json($result);
    }
}
