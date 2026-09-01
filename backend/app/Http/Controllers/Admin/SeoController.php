<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SeoController as PublicSeoController;
use App\Http\Requests\Seo\UpdateSeoSettingsRequest;
use App\Models\Game;
use App\Models\Redirect;
use App\Models\Reseller;
use App\Models\ResellerSeoSettings;
use Illuminate\Http\JsonResponse;

/**
 * ADR-029 decisions 2/4/6/10: the SEO page's "Global Settings"/"Meta
 * Templates" tabs (one table, decision 2) plus the Overview tab
 * (decision 4/addendum 2 decision 17 — a computed checklist +
 * recommendations + recent issues, never a weighted score). Same
 * admin.role:super_admin tier as Settings (decision 6 — no reseller
 * self-service portal yet).
 */
class SeoController extends Controller
{
    public function overview(): JsonResponse
    {
        $reseller = Reseller::primary();

        $totalGames = Game::query()->where('is_active', true)->count();
        $missingTitle = Game::query()->where('is_active', true)->whereNull('seo_title')->count();
        $missingDescription = Game::query()->where('is_active', true)->whereNull('seo_description')->count();
        $missingOgImage = Game::query()->where('is_active', true)->whereNull('seo_og_image')->count();
        $redirectCount = Redirect::query()->where('reseller_id', $reseller->id)->count();

        $recommendations = [];
        if ($missingTitle > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'message' => "{$missingTitle} active game(s) missing an SEO title.",
                // Not filter=incomplete: that status means "one of
                // title/description set, one missing" — a fresh/empty
                // catalog (both fields null on every game) is
                // filter=missing instead, and a title-only or
                // description-only gap doesn't map onto either status
                // cleanly. Link to the unfiltered list — status badges
                // there make the actual gap visible per row.
                'link' => '/admin/seo/games',
            ];
        }
        if ($missingDescription > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'message' => "{$missingDescription} active game(s) missing an SEO description.",
                // Not filter=incomplete: that status means "one of
                // title/description set, one missing" — a fresh/empty
                // catalog (both fields null on every game) is
                // filter=missing instead, and a title-only or
                // description-only gap doesn't map onto either status
                // cleanly. Link to the unfiltered list — status badges
                // there make the actual gap visible per row.
                'link' => '/admin/seo/games',
            ];
        }
        if ($missingOgImage > 0) {
            $recommendations[] = [
                'severity' => 'info',
                'message' => "{$missingOgImage} active game(s) missing an OG image.",
                // Not filter=incomplete: that status means "one of
                // title/description set, one missing" — a fresh/empty
                // catalog (both fields null on every game) is
                // filter=missing instead, and a title-only or
                // description-only gap doesn't map onto either status
                // cleanly. Link to the unfiltered list — status badges
                // there make the actual gap visible per row.
                'link' => '/admin/seo/games',
            ];
        }

        return response()->json([
            'games' => [
                'total' => $totalGames,
                'complete' => $totalGames - max($missingTitle, $missingDescription, $missingOgImage),
                'missing_title' => $missingTitle,
                'missing_description' => $missingDescription,
                'missing_og_image' => $missingOgImage,
            ],
            'redirects' => ['total' => $redirectCount],
            'sitemap_url' => '/sitemap.xml',
            // addendum 2 decision 16: a status line, not a setting — 5
            // static storefront routes (home/terms/privacy/about-us/
            // track-order) + one per active game, mirroring
            // storefront's own app/sitemap.ts exactly.
            'sitemap_url_count' => 5 + $totalGames,
            'recommendations' => $recommendations,
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->settingsFor(Reseller::primary()));
    }

    public function updateSettings(UpdateSeoSettingsRequest $request): JsonResponse
    {
        $reseller = Reseller::primary();
        $settings = $this->settingsFor($reseller);
        $settings->update($request->validated());
        PublicSeoController::forgetCache($reseller->id);
        // crawler_default_disallow_paths lives here but is merged into
        // the /robots.txt response (PublicSeoController::robots()) —
        // that endpoint's own cache must also be invalidated on save,
        // not just this row's own cache keys.
        PublicSeoController::forgetRobotsCache();

        return response()->json($settings);
    }

    private function settingsFor(Reseller $reseller): ResellerSeoSettings
    {
        return ResellerSeoSettings::query()->firstOrCreate(['reseller_id' => $reseller->id]);
    }
}
