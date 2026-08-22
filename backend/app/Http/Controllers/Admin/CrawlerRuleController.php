<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SeoController as PublicSeoController;
use App\Http\Requests\Seo\SaveCrawlerRuleRequest;
use App\Models\CrawlerRule;
use Illuminate\Http\JsonResponse;

/** ADR-029 addendum 2 decision 14: admin CRUD over robots.txt bot rules. */
class CrawlerRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(CrawlerRule::query()->orderBy('sort_order')->get());
    }

    public function store(SaveCrawlerRuleRequest $request): JsonResponse
    {
        $rule = CrawlerRule::query()->create($request->validated());
        PublicSeoController::forgetRobotsCache();

        return response()->json($rule, 201);
    }

    public function update(SaveCrawlerRuleRequest $request, CrawlerRule $crawlerRule): JsonResponse
    {
        $crawlerRule->update($request->validated());
        PublicSeoController::forgetRobotsCache();

        return response()->json($crawlerRule);
    }

    public function destroy(CrawlerRule $crawlerRule): JsonResponse
    {
        $crawlerRule->delete();
        PublicSeoController::forgetRobotsCache();

        return response()->json(null, 204);
    }
}
