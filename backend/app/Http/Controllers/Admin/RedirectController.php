<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SeoController as PublicSeoController;
use App\Http\Requests\Seo\SaveRedirectRequest;
use App\Models\Redirect;
use App\Models\Reseller;
use Illuminate\Http\JsonResponse;

/** ADR-029 decision 3/9: admin CRUD over reseller-scoped path redirects. */
class RedirectController extends Controller
{
    public function index(): JsonResponse
    {
        $reseller = Reseller::primary();

        return response()->json(
            Redirect::query()->where('reseller_id', $reseller->id)->orderByDesc('hit_count')->get(),
        );
    }

    public function store(SaveRedirectRequest $request): JsonResponse
    {
        $reseller = Reseller::primary();
        $redirect = Redirect::query()->create([...$request->validated(), 'reseller_id' => $reseller->id]);
        PublicSeoController::forgetCache($reseller->id);

        return response()->json($redirect, 201);
    }

    public function update(SaveRedirectRequest $request, Redirect $redirect): JsonResponse
    {
        $redirect->update($request->validated());
        PublicSeoController::forgetCache($redirect->reseller_id);

        return response()->json($redirect);
    }

    public function destroy(Redirect $redirect): JsonResponse
    {
        $resellerId = $redirect->reseller_id;
        $redirect->delete();
        PublicSeoController::forgetCache($resellerId);

        return response()->json(null, 204);
    }
}
