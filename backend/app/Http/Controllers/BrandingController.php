<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Reseller;
use App\Models\ResellerBranding;
use App\Models\ResellerFooterSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Mews\Purifier\Facades\Purifier;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ADR-028 + its 2026-08-22 addendum: public, guest-callable storefront
 * branding/footer/legal content — same no-auth reasoning as
 * CatalogController/HeroSlideController (ADR-011). Public call site #5
 * for Reseller::platformOwner() — see that method's doc comment.
 */
class BrandingController extends Controller
{
    private const CACHE_TTL_SECONDS = 60;

    private const LEGAL_PAGES = [
        'terms' => 'terms_content',
        'privacy' => 'privacy_content',
        'about-us' => 'about_us_content',
    ];

    public function show(): JsonResponse
    {
        $reseller = Reseller::platformOwner();

        $payload = Cache::remember(
            "catalog.public.branding.{$reseller->id}",
            self::CACHE_TTL_SECONDS,
            function () use ($reseller) {
                $branding = ResellerBranding::query()->where('reseller_id', $reseller->id)->first();
                $footer = ResellerFooterSettings::query()->where('reseller_id', $reseller->id)->first();
                $storeName = $branding?->store_name ?? $reseller->business_name;

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
                    'support_email' => $branding?->support_email,
                    'support_phone' => $branding?->support_phone,
                    'telegram_contact_link' => $branding?->telegram_contact_link,
                    'social_links' => $branding?->social_links ?? [],
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
            throw new NotFoundHttpException();
        }

        $reseller = Reseller::platformOwner();
        $column = self::LEGAL_PAGES[$page];

        $payload = Cache::remember(
            "catalog.public.branding.{$reseller->id}.legal.{$page}",
            self::CACHE_TTL_SECONDS,
            function () use ($reseller, $column) {
                $branding = ResellerBranding::query()->where('reseller_id', $reseller->id)->first();
                $footer = ResellerFooterSettings::query()->where('reseller_id', $reseller->id)->first();
                $storeName = $branding?->store_name ?? $reseller->business_name;
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

    public static function forgetCache(int $resellerId): void
    {
        Cache::forget("catalog.public.branding.{$resellerId}");
        foreach (array_keys(self::LEGAL_PAGES) as $page) {
            Cache::forget("catalog.public.branding.{$resellerId}.legal.{$page}");
        }
    }
}
