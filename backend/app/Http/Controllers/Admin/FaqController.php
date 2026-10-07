<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SeoController as PublicSeoController;
use App\Http\Requests\Seo\SaveFaqRequest;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;

/** ADR-120 decision 13: admin CRUD over the platform-wide FAQ. */
class FaqController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Faq::query()->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(SaveFaqRequest $request): JsonResponse
    {
        $faq = Faq::query()->create($request->validated());
        PublicSeoController::forgetFaqCache();

        return response()->json($faq, 201);
    }

    public function update(SaveFaqRequest $request, Faq $faq): JsonResponse
    {
        $faq->update($request->validated());
        PublicSeoController::forgetFaqCache();

        return response()->json($faq);
    }

    public function destroy(Faq $faq): JsonResponse
    {
        $faq->delete();
        PublicSeoController::forgetFaqCache();

        return response()->json(null, 204);
    }
}
