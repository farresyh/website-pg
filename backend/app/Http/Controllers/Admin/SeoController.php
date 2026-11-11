<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SeoController as PublicSeoController;
use App\Http\Requests\Seo\UpdateSeoSettingsRequest;
use App\Models\Affiliate;
use App\Models\AffiliateSeoSettings;
use App\Models\Game;
use App\Models\Redirect;
use Illuminate\Http\JsonResponse;

/**
 * ADR-029 decisions 2/4/6/10: the SEO page's "Global Settings"/"Meta
 * Templates" tabs (one table, decision 2) plus the Overview tab
 * (decision 4/addendum 2 decision 17 — a computed checklist +
 * recommendations + recent issues, never a weighted score). Same
 * admin.role:super_admin tier as Settings (decision 6 — no affiliate
 * self-service portal yet).
 */
class SeoController extends Controller
{
    public function overview(): JsonResponse
    {
        $affiliate = Affiliate::primary();

        // ADR-140 decision 8: one status over the tracked games (active, not noindex),
        // the set the sitemap lists. The per-game SEO overrides are exceptions and
        // never count as "missing"; this replaces the old title/description/OG-image
        // recommendations, which told the founder to fill what ADR-120 left blank.
        $byStatus = Game::query()->tracked()
            ->get(['id', 'content_summary', 'content_about', 'content_faq', 'content_published_at'])
            ->countBy(fn (Game $game) => $game->contentStatus());
        $published = $byStatus->get('published', 0);
        $draft = $byStatus->get('draft', 0);
        $empty = $byStatus->get('empty', 0);
        $total = $published + $draft + $empty;
        $redirectCount = Redirect::query()->where('affiliate_id', $affiliate->id)->count();

        $recommendations = [];
        if ($empty > 0) {
            $recommendations[] = [
                'severity' => 'warning',
                'message' => "{$empty} active game(s) have no page content.",
                'link' => '/admin/seo/games?filter=empty',
            ];
        }
        if ($draft > 0) {
            $recommendations[] = [
                'severity' => 'info',
                'message' => "{$draft} game(s) have a draft that is not published.",
                'link' => '/admin/seo/games?filter=draft',
            ];
        }

        return response()->json([
            'games' => [
                'total' => $total,
                'published' => $published,
                'draft' => $draft,
                'empty' => $empty,
            ],
            'redirects' => ['total' => $redirectCount],
            'sitemap_url' => '/sitemap.xml',
            // addendum 2 decision 16: a status line, not a setting — the 6 static
            // routes plus one per tracked game. The optional pages (price list,
            // Game ID Checker, joki) depend on the brand and are not counted here.
            'sitemap_url_count' => 6 + $total,
            'recommendations' => $recommendations,
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->settingsFor(Affiliate::primary()));
    }

    public function updateSettings(UpdateSeoSettingsRequest $request): JsonResponse
    {
        $affiliate = Affiliate::primary();
        $settings = $this->settingsFor($affiliate);
        $settings->update($request->validated());
        PublicSeoController::forgetCache($affiliate->id);
        // crawler_default_disallow_paths lives here but is merged into
        // the /robots.txt response (PublicSeoController::robots()) —
        // that endpoint's own cache must also be invalidated on save,
        // not just this row's own cache keys.
        PublicSeoController::forgetRobotsCache();

        return response()->json($settings);
    }

    private function settingsFor(Affiliate $affiliate): AffiliateSeoSettings
    {
        return AffiliateSeoSettings::query()->firstOrCreate(['affiliate_id' => $affiliate->id]);
    }
}
