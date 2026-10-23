<?php

namespace App\Http\Controllers;

use App\Http\Requests\Joki\JokiQuoteRequest;
use App\Models\JokiRoute;
use App\Models\JokiSetting;
use App\Models\JokiTier;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Services\Joki\JokiCatalog;
use App\Services\Joki\JokiLadder;
use App\Services\Joki\JokiPricingService;
use Illuminate\Http\JsonResponse;

/**
 * ADR-126 decision 12: what the `/joki` page reads — the rank model with
 * its customer rates, the route packages, the public settings and the
 * login form — and a live quote through the same JokiPricingService the
 * checkout uses. The payout share and every cost stay out (the platform's
 * cost structure is private).
 */
class JokiCatalogController extends Controller
{
    public function show(JokiCatalog $catalog): JsonResponse
    {
        $calculator = $catalog->calculator();
        abort_if($calculator === null, 404, 'Joki is not set up.');
        $game = $calculator->game;
        $settings = JokiSetting::current();
        $ladder = JokiLadder::current();
        $routes = JokiRoute::query()
            ->with('package:id,name,is_active')
            ->get()
            ->filter(fn (JokiRoute $r) => (bool) $r->package?->is_active)
            ->sortBy('id')
            ->values();

        return response()->json([
            'game' => ['id' => $game->id, 'slug' => $game->slug, 'name' => $game->name, 'image_url' => $game->image_url],
            'calculator_package_id' => $calculator->id,
            'tiers' => $ladder->tiers()->map(fn (JokiTier $t) => $t->only([
                'id', 'name', 'kind', 'divisions', 'stars_per_division', 'mythic_from_stars', 'rate_rm', 'express_rate_rm', 'icon_url',
            ]))->values(),
            'routes' => $routes->map(fn (JokiRoute $r) => [
                'package_id' => $r->package_id,
                'name' => $r->package->name,
                'from' => $ladder->position($r->fromRank(), 'route')->toArray(),
                'to' => $ladder->position($r->toRank(), 'route')->toArray(),
                'price_rm' => $r->is_quotation ? null : $r->price_rm,
                'is_quotation' => $r->is_quotation,
            ]),
            'settings' => [
                'min_stars' => $settings->min_stars,
                'grading_price_rm' => $settings->grading_price_rm,
                'grading_express_price_rm' => $settings->grading_express_price_rm,
                'grading_lands_at_stars' => $settings->grading_lands_at_stars,
                'max_mythic_stars' => $settings->max_mythic_stars,
                'roles' => $settings->roles ?? [],
                'express_description' => $settings->express_description,
                'terms_html' => $settings->terms_html,
            ],
            'credential_fields' => $game->credentialFields(),
            'credential_consent_text' => PlatformSettings::current()->credential_consent_text,
        ]);
    }

    public function quote(JokiQuoteRequest $request, JokiPricingService $pricing): JsonResponse
    {
        $package = Package::query()->findOrFail($request->validated('package_id'));
        $joki = (array) $request->validated('joki', []);

        return response()->json($pricing->quote($package, (array) ($joki['from'] ?? []), (array) ($joki['to'] ?? []), (bool) ($joki['is_express'] ?? false))->toArray());
    }
}
