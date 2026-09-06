<?php

namespace App\Http\Controllers\Affiliate\Storefront;

use App\Http\Controllers\Affiliate\Concerns\AssertsAffiliateWritable;
use App\Http\Controllers\Controller;
use App\Http\Controllers\HeroSlideController as PublicHeroSlideController;
use App\Http\Requests\Affiliate\Storefront\SaveHeroSlideRequest;
use App\Http\Requests\Affiliate\Storefront\UpdateHeroSlideStatusRequest;
use App\Models\HeroSlide;
use App\Services\Media\ImageIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-060 PR-6 — the Hero tab. An affiliate manages their own slides
 * (`hero_slides` rows with their `affiliate_id`); the global / primary
 * slides (null `affiliate_id`, edited in `/admin/hero-slides`) are the
 * fallback when this brand has zero active slides.
 *
 * Every query is `BelongsToAffiliate`-scoped, so route-model binding on
 * `{heroSlide}` 404s a slide that isn't this tenant's (including any
 * global slide) with no explicit ownership check.
 */
class HeroSlideController extends Controller
{
    use AssertsAffiliateWritable;

    /** ADR-060 PR-6 planning addendum Q7: per-affiliate slide cap. */
    private const MAX_SLIDES = 6;

    /** ADR-060 PR-6 planning addendum decision 4: hero longest edge. */
    private const IMAGE_MAX_EDGE = 1600;

    public function __construct(private readonly ImageIngestService $images) {}

    public function index(Request $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();

        $slides = HeroSlide::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (HeroSlide $s) => $this->shape($s));

        return response()->json([
            'slides' => $slides,
            'max_slides' => self::MAX_SLIDES,
            'writable' => $affiliate->status === 'active',
        ]);
    }

    public function store(SaveHeroSlideRequest $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $this->assertWritable($affiliate);

        if (HeroSlide::query()->count() >= self::MAX_SLIDES) {
            return response()->json([
                'message' => 'You can have at most '.self::MAX_SLIDES.' hero slides. Remove one first.',
            ], 422);
        }

        $path = $this->images->ingest(
            $request->file('image'),
            $this->imagePath($affiliate->id),
            self::IMAGE_MAX_EDGE,
        );

        $slide = new HeroSlide($this->textFields($request));
        $slide->affiliate_id = $affiliate->id;
        $slide->image_path = $path;
        $slide->image_url = null;
        $slide->save();

        PublicHeroSlideController::forgetCache($affiliate->id);

        return response()->json($this->shape($slide), 201);
    }

    public function update(SaveHeroSlideRequest $request, HeroSlide $heroSlide): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $this->assertWritable($affiliate);

        $slide = $heroSlide->fill($this->textFields($request));

        if ($request->hasFile('image')) {
            $old = $slide->image_path;
            $slide->image_path = $this->images->ingest(
                $request->file('image'),
                $this->imagePath($affiliate->id),
                self::IMAGE_MAX_EDGE,
            );
            $slide->image_url = null;
            $slide->save();
            $this->images->delete($old);
        } else {
            $slide->save();
        }

        PublicHeroSlideController::forgetCache($affiliate->id);

        return response()->json($this->shape($slide));
    }

    public function updateStatus(UpdateHeroSlideStatusRequest $request, HeroSlide $heroSlide): JsonResponse
    {
        $this->assertWritable($request->user()->affiliateOwner());

        $heroSlide->update(['is_active' => $request->boolean('is_active')]);
        PublicHeroSlideController::forgetCache($heroSlide->affiliate_id);

        return response()->json($this->shape($heroSlide));
    }

    public function destroy(Request $request, HeroSlide $heroSlide): Response
    {
        $this->assertWritable($request->user()->affiliateOwner());

        $affiliateId = $heroSlide->affiliate_id;
        $this->images->delete($heroSlide->image_path);
        $heroSlide->delete();
        PublicHeroSlideController::forgetCache($affiliateId);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function textFields(SaveHeroSlideRequest $request): array
    {
        return $request->safe()->only([
            'eyebrow', 'title', 'description', 'price_from_sen',
            'primary_cta_label', 'primary_cta_href',
            'secondary_cta_label', 'secondary_cta_href',
            'is_active', 'sort_order',
        ]);
    }

    private function imagePath(int $affiliateId): string
    {
        return "affiliate-hero/{$affiliateId}/".Str::uuid()->toString().'.webp';
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(HeroSlide $slide): array
    {
        return [
            'id' => $slide->id,
            'eyebrow' => $slide->eyebrow,
            'title' => $slide->title,
            'description' => $slide->description,
            'image_url' => $slide->resolvedImageUrl(),
            'price_from_sen' => $slide->price_from_sen,
            'primary_cta_label' => $slide->primary_cta_label,
            'primary_cta_href' => $slide->primary_cta_href,
            'secondary_cta_label' => $slide->secondary_cta_label,
            'secondary_cta_href' => $slide->secondary_cta_href,
            'is_active' => $slide->is_active,
            'sort_order' => $slide->sort_order,
        ];
    }
}
