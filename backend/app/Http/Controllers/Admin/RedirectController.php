<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SeoController as PublicSeoController;
use App\Http\Requests\Seo\SaveRedirectRequest;
use App\Models\Affiliate;
use App\Models\Redirect;
use Illuminate\Http\JsonResponse;

/** ADR-029 decision 3/9: admin CRUD over affiliate-scoped path redirects. */
class RedirectController extends Controller
{
    public function index(): JsonResponse
    {
        $affiliate = Affiliate::primary();

        return response()->json(
            Redirect::query()->where('affiliate_id', $affiliate->id)->orderByDesc('hit_count')->get(),
        );
    }

    public function store(SaveRedirectRequest $request): JsonResponse
    {
        $affiliate = Affiliate::primary();
        $redirect = Redirect::query()->create([...$request->validated(), 'affiliate_id' => $affiliate->id]);
        PublicSeoController::forgetCache($affiliate->id);

        return response()->json($redirect, 201);
    }

    public function update(SaveRedirectRequest $request, Redirect $redirect): JsonResponse
    {
        $redirect->update($request->validated());
        PublicSeoController::forgetCache($redirect->affiliate_id);

        return response()->json($redirect);
    }

    public function destroy(Redirect $redirect): JsonResponse
    {
        $affiliateId = $redirect->affiliate_id;
        $redirect->delete();
        PublicSeoController::forgetCache($affiliateId);

        return response()->json(null, 204);
    }
}
