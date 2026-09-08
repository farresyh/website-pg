<?php

namespace App\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateFooterSettings;
use App\Models\Game;
use App\Services\Cache\NextRevalidation;
use App\Support\StorefrontBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Mews\Purifier\Facades\Purifier;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ADR-028 + its 2026-08-22 addendum: public, guest-callable storefront
 * branding/footer/legal content — same no-auth reasoning as
 * CatalogController/HeroSlideController (ADR-011). The brand is resolved
 * per `Host` via `storefront.brand` middleware (ADR-060); `StorefrontBrand`
 * falls back to `Affiliate::primary()` when no `X-Storefront-Host` is set.
 */
class BrandingController extends Controller
{
    private const CACHE_TTL_SECONDS = 60;

    private const LEGAL_PAGES = [
        'terms' => 'terms_content',
        'privacy' => 'privacy_content',
        'about-us' => 'about_us_content',
    ];

    public function __construct(private readonly StorefrontBrand $brand) {}

    public function show(): JsonResponse
    {
        $affiliate = $this->brand->get();

        $payload = Cache::remember(
            "catalog.public.branding.{$affiliate->id}",
            self::CACHE_TTL_SECONDS,
            function () use ($affiliate) {
                $branding = AffiliateBranding::query()->where('affiliate_id', $affiliate->id)->first();
                $footer = AffiliateFooterSettings::query()->where('affiliate_id', $affiliate->id)->first();
                $storeName = $branding?->store_name ?? $affiliate->business_name;

                $footerGames = Game::query()
                    ->whereIn('id', $footer?->footer_game_ids ?? [])
                    ->get(['id', 'name', 'slug'])
                    ->keyBy('id');

                // Preserve the admin-picked order — footer_game_ids IS the
                // display order (ADR-028 addendum decision 14), a plain
                // whereIn() would silently reorder by id instead.
                //
                // ->toArray() here is load-bearing, not cosmetic: backend/
                // CLAUDE.md's own documented cache convention (ADR-014's
                // addendum) — Cache::remember() must never store a raw
                // Eloquent Model/Collection, the database driver silently
                // corrupts it into __PHP_Incomplete_Class on the next
                // read. Confirmed live: without this, every cache HIT
                // (i.e. every request except the first one every 60s)
                // returned a truncated footer_games array.
                $orderedFooterGames = collect($footer?->footer_game_ids ?? [])
                    ->map(fn ($id) => $footerGames->get($id))
                    ->filter()
                    ->values()
                    ->toArray();

                return [
                    'store_name' => $storeName,
                    'description' => $branding?->description,
                    // ADR-060 PR-6: derived from `logo_path` via the
                    // gallery disk — a plain string URL or null, safe to
                    // cache (no object-corruption concern).
                    'logo_url' => $branding?->logo_url,
                    'support_email' => $branding?->support_email,
                    'support_phone' => $branding?->support_phone,
                    'telegram_contact_link' => $branding?->telegram_contact_link,
                    'social_links' => $branding?->social_links ?: null,
                    'footer_text' => self::substitute($footer?->footer_text, $storeName),
                    'footer_games' => $orderedFooterGames,
                ];
            },
        );

        return response()->json($payload);
    }

    /**
     * Decision 11's three routes (/terms, /privacy, /about-us) all
     * resolve through here. Decision 12: sanitized again defensively
     * at this render point, on top of the sanitize-on-save
     * SettingsController already did — belt and braces, not trusting
     * stored data forever just because it was clean once.
     */
    public function legal(string $page): JsonResponse
    {
        if (! array_key_exists($page, self::LEGAL_PAGES)) {
            throw new NotFoundHttpException;
        }

        // ADR-060 addendum: footer + legal content stays admin-only and
        // central — every brand renders the primary's legal text (the
        // `{store_name}` substitution against the resolved brand).
        $resolvedBrand = $this->brand->get();
        $primary = Affiliate::query()->where('is_primary', true)->first() ?? $resolvedBrand;
        $column = self::LEGAL_PAGES[$page];

        $payload = Cache::remember(
            "catalog.public.branding.{$resolvedBrand->id}.legal.{$page}",
            self::CACHE_TTL_SECONDS,
            function () use ($resolvedBrand, $primary, $column) {
                $branding = AffiliateBranding::query()->where('affiliate_id', $resolvedBrand->id)->first();
                $footer = AffiliateFooterSettings::query()->where('affiliate_id', $primary->id)->first();
                $storeName = $branding?->store_name ?? $resolvedBrand->business_name;
                $content = $footer?->{$column};

                return [
                    'content' => $content === null ? null : Purifier::clean(self::substitute($content, $storeName), 'rich_text'),
                ];
            },
        );

        return response()->json($payload);
    }

    /**
     * Decision 13: only the exact literal `{store_name}` token is
     * replaced — any other brace sequence (a typo, an unrecognized
     * token) is left verbatim, no error.
     */
    private static function substitute(?string $content, string $storeName): ?string
    {
        return $content === null ? null : str_replace('{store_name}', $storeName, $content);
    }

    public static function forgetCache(int $affiliateId): void
    {
        Cache::forget("catalog.public.branding.{$affiliateId}");
        foreach (array_keys(self::LEGAL_PAGES) as $page) {
            Cache::forget("catalog.public.branding.{$affiliateId}.legal.{$page}");
        }

        $primary = Affiliate::query()->where('is_primary', true)->first();
        if ($primary && $affiliateId === $primary->id) {
            $otherAffiliates = Affiliate::query()->where('id', '!=', $primary->id)->pluck('id');
            foreach ($otherAffiliates as $otherId) {
                foreach (array_keys(self::LEGAL_PAGES) as $page) {
                    Cache::forget("catalog.public.branding.{$otherId}.legal.{$page}");
                }
            }
        }

        NextRevalidation::purge(); // ADR-071 PR2
    }
}
