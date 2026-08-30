<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SeoController as PublicSeoController;
use App\Http\Requests\Seo\SaveSeoScriptRequest;
use App\Models\Reseller;
use App\Models\SeoScript;
use Illuminate\Http\JsonResponse;

/** ADR-029 addendum 2 decision 13: admin CRUD over head/body_end scripts. */
class SeoScriptController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(SeoScript::query()->orderBy('priority')->get());
    }

    public function store(SaveSeoScriptRequest $request): JsonResponse
    {
        $script = SeoScript::query()->create($request->validated());
        PublicSeoController::forgetCache(Reseller::primary()->id);

        return response()->json($script, 201);
    }

    public function update(SaveSeoScriptRequest $request, SeoScript $seoScript): JsonResponse
    {
        $seoScript->update($request->validated());
        PublicSeoController::forgetCache(Reseller::primary()->id);

        return response()->json($seoScript);
    }

    public function destroy(SeoScript $seoScript): JsonResponse
    {
        $seoScript->delete();
        PublicSeoController::forgetCache(Reseller::primary()->id);

        return response()->json(null, 204);
    }
}
