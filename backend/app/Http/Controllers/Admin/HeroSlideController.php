<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HeroSlideController as PublicHeroSlideController;
use App\Http\Requests\HeroSlides\SaveHeroSlideRequest;
use App\Http\Requests\HeroSlides\UpdateHeroSlideStatusRequest;
use App\Models\HeroSlide;
use Illuminate\Http\JsonResponse;

/**
 * docs/prd.md §14/§15 backlog: "Hero Banner / Campaign management."
 * Deliberately separate from a future Promotions feature (founder
 * decision, 2026-07-26) — a hero slide is admin-authored marketing
 * copy (image + text + CTA links), never tied to a real Game/Package
 * or a computed price, unlike Promotions' planned "real product, real
 * discounted price" shape.
 *
 * No money field exists on this model, so unlike Games/Packages this
 * controller doesn't need the public-controller split for a
 * secret-field-leakage reason — it's split anyway (see
 * HeroSlideController, the public read-only sibling) to keep the
 * "public routes live in a controller with no admin actions at all"
 * discipline consistent across the codebase.
 */
class HeroSlideController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            HeroSlide::query()->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    public function store(SaveHeroSlideRequest $request): JsonResponse
    {
        $slide = HeroSlide::query()->create($request->validated());
        PublicHeroSlideController::forgetCache();

        return response()->json($slide, 201);
    }

    public function update(SaveHeroSlideRequest $request, HeroSlide $heroSlide): JsonResponse
    {
        $heroSlide->update($request->validated());
        PublicHeroSlideController::forgetCache();

        return response()->json($heroSlide);
    }

    public function updateStatus(UpdateHeroSlideStatusRequest $request, HeroSlide $heroSlide): JsonResponse
    {
        $heroSlide->update(['is_active' => $request->validated('is_active')]);
        PublicHeroSlideController::forgetCache();

        return response()->json($heroSlide);
    }

    public function destroy(HeroSlide $heroSlide): JsonResponse
    {
        $heroSlide->delete();
        PublicHeroSlideController::forgetCache();

        return response()->json(null, 204);
    }
}
