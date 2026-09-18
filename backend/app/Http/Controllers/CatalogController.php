<?php

namespace App\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateGame;
use App\Models\Game;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Package;
use App\Services\Cache\NextRevalidation;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Membership\MembershipStatus;
use App\Services\Pricing\MembershipPricingService;
use App\Services\Pricing\PricingService;
use App\Services\Reseller\ResellerCatalogService;
use App\Support\StorefrontBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Public, guest-callable game/package catalog (ADR-011, same no-auth
 * reasoning as CheckoutController/PlayerValidationController) — the
 * storefront's real data source, replacing
 * storefront/src/lib/placeholder-data.ts (docs/prd.md §14/§15's NEXT
 * SESSION pointer, "public catalog endpoint").
 *
 * Deliberately a separate controller from GameController (admin-only,
 * `admin.role`-gated): mixing a public read path into an admin
 * controller risks a routing mistake exposing admin-only data. Never
 * returns cost_price/standard_selling_price/markup_percent/supplier_id/
 * supplier_package_ref (GameController::packages()'s own fields, the
 * platform's wholesale cost and margin) — only a computed
 * `selling_price_sen`, the same customer-facing price
 * CheckoutService prices an order at (PricingService, against the
 * Platform Owner Affiliate row, per ADR-013).
 *
 * Lookup is always by slug, not the admin routes' numeric `{id}` — the
 * storefront's own URLs (`/order/[slug]`) are slug-based, and slug is
 * the one identifier safe to expose publicly as a lookup key.
 */
class CatalogController extends Controller
{
    /** ADR-014: same 60s TTL/invalidate-on-write discipline as GameController. */
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly MembershipPricingService $membershipPricing,
        private readonly MembershipSessionTokenService $membershipSessionTokens,
        private readonly StorefrontBrand $storefrontBrand,
    ) {}

    public function index(): JsonResponse
    {
        // ADR-060 PR-4c: `price_from_sen` is priced against the
        // `Host`-resolved brand's wholesale tier + markup, so the whole
        // listing is cached per brand. The primary brand keeps its own
        // id-keyed entry — one code path, no bare-key special case.
        $brandId = $this->storefrontBrand->get()->id;

        $games = Cache::tags(['catalog.index', "catalog.index.brand.{$brandId}"])->remember(
            self::indexCacheKey($brandId),
            self::CACHE_TTL_SECONDS,
            fn () => Game::query()
                ->where('is_active', true)
                ->whereNotIn('id', $this->hiddenGameIds())
                ->with(['packages' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Game $game) => $this->publicGameSummary($game))
                ->all(),
        );

        return response()->json($games);
    }

    public function show(string $slug): JsonResponse
    {
        $game = $this->findActiveGame($slug);

        if ($game === null) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        return response()->json($this->publicGameDetail($game));
    }

    public function packages(string $slug, Request $request): JsonResponse
    {
        $game = $this->findActiveGame($slug);

        if ($game === null) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        // ADR-061 decision 4: Membership is live only when the global
        // kill-switch AND this storefront's own toggle are both on.
        // ADR-060 PR-4c: the brand is resolved per `Host` — its own
        // wholesale tier + markup drive `selling_price_sen` below.
        $affiliate = $this->storefrontBrand->get();
        $membershipEnabled = $affiliate->membershipEnabledEffective();

        // Resolved once per request, outside the cache closure below —
        // a decrypt + one indexed Membership lookup, not worth caching
        // itself, and it must run on every request since it identifies
        // *this* caller, unlike the shared anchor price the closure
        // computes. A missing/unresolvable/lapsed token falls back to
        // null, same silent fallback CheckoutController::
        // resolveMembershipId() already uses — not an error.
        $memberPlan = $membershipEnabled ? $this->resolveMemberPlan($request, $affiliate->id) : null;

        $packages = Cache::store(config('cache.catalog_packages_store'))
            // ADR-060 PR-6: the per-brand tag lets an affiliate's markup
            // change flush only its own brand's package prices, never the
            // whole catalog.
            ->tags(['catalog.packages', "catalog.packages.game.{$game->id}", "catalog.packages.brand.{$affiliate->id}"])
            ->remember(
                self::packagesCacheKey($game->id, $memberPlan?->id, $affiliate->id),
                self::CACHE_TTL_SECONDS,
                function () use ($game, $membershipEnabled, $memberPlan) {
                    // The caller's own tier when this request carried a
                    // valid membership session token; otherwise the
                    // same anonymous "best tier" anchor as before
                    // (ADR-027's 2026-08-29 addendum, decision 21) —
                    // never a mix of the two within one cached entry.
                    $anchorPlan = $memberPlan ?? ($membershipEnabled
                        ? MembershipPlan::query()->orderByDesc('discount_percent')->first()
                        : null);

                    return $this->dedupByDenomination(
                        $game->packages()
                            ->where('is_active', true)
                            ->get(),
                    )
                        ->map(fn (Package $package) => $this->publicPackage($package, $anchorPlan, $memberPlan !== null))
                        ->all();
                },
            );

        return response()->json($packages);
    }

    /**
     * Mirrors CheckoutController::resolveMembershipId's own token
     * resolution (same MembershipSessionTokenService, same
     * Active+unexpired check) but returns the member's own
     * MembershipPlan so publicPackage() can price against their real
     * tier instead of the anonymous "best tier" anchor — the bug this
     * method fixes: a Tier 1 member was always shown Tier 2's anchor
     * price pre-payment, even though CheckoutService already charged
     * them correctly at Tier 1 (docs/adr.md ADR-027).
     */
    private function resolveMemberPlan(Request $request, int $affiliateId): ?MembershipPlan
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        $session = $this->membershipSessionTokens->resolve($token);

        // ADR-061 decision 5: a token from another brand's storefront is
        // ignored here — the caller falls back to the anonymous anchor
        // price, same as an unauthenticated request.
        if ($session === null || $session['affiliate_id'] !== $affiliateId) {
            return null;
        }

        return Membership::query()
            ->where('affiliate_id', $affiliateId)
            ->where('email', $session['email'])
            ->where('status', MembershipStatus::Active)
            ->where('expires_at', '>=', now())
            ->with('membershipPlan')
            ->first()
            ?->membershipPlan;
    }

    private function findActiveGame(string $slug): ?Game
    {
        return Game::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->whereNotIn('id', $this->hiddenGameIds())
            ->first();
    }

    /**
     * ADR-060 PR-6: game ids the `Host`-resolved brand has explicitly
     * turned off (`affiliate_game.is_visible = false`). Absent row =
     * visible, so this is the whole exclusion set. Memoised per request.
     * The primary brand has no such rows — its storefront always shows
     * the full catalog.
     *
     * @var list<int>|null
     */
    private ?array $hiddenGameIds = null;

    /**
     * @return list<int>
     */
    private function hiddenGameIds(): array
    {
        return $this->hiddenGameIds ??= AffiliateGame::query()
            ->where('affiliate_id', $this->storefrontBrand->get()->id)
            ->where('is_visible', false)
            ->pluck('game_id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function publicGameSummary(Game $game): array
    {
        $activePackages = $game->packages->where('is_active', true);
        $cheapest = $activePackages->isEmpty() ? null : $activePackages->min(
            fn (Package $package) => $this->sellingPriceSen($package),
        );

        return [
            'id' => $game->id,
            'slug' => $game->slug,
            'name' => $game->name,
            'category' => $game->category,
            'image_url' => $game->image_url,
            'banner_url' => $game->banner_url,
            'extra_field' => $game->validation_rules['extra_field'] ?? null,
            'player_validator_enabled' => $game->player_validator_enabled,
            'price_from_sen' => $cheapest,
            // Cast to a plain string, never left as a Carbon instance:
            // this app's `database` cache store corrupts any raw
            // object nested in a cached value on the next read
            // (confirmed live, see HeroSlideController's doc comment)
            // — a plain array of scalars is not, on its own, enough if
            // one of those "scalars" is secretly still a Carbon object.
            'created_at' => $game->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicGameDetail(Game $game): array
    {
        return [
            'id' => $game->id,
            'slug' => $game->slug,
            'name' => $game->name,
            'category' => $game->category,
            'image_url' => $game->image_url,
            'banner_url' => $game->banner_url,
            'extra_field' => $game->validation_rules['extra_field'] ?? null,
            // ADR-097 decision 9/17 — flat sibling of extra_field, not
            // nested (the Reseller API's own checkout_input object is
            // a separately-designed public contract, decision 22).
            // null/empty preserves today's free-text <input> exactly.
            'zone_options' => $game->zoneOptions(),
            'player_validator_enabled' => $game->player_validator_enabled,
            // ADR-109 decisions 11/12 — flat siblings, same shape as
            // extra_field/zone_options above. important_notes/
            // description gate the storefront's auto-open modal;
            // delivery_mode/delivery_subtext feed ProductHeaderCard's
            // badge, which used to hardcode this for every game.
            'description' => $game->description,
            'important_notes' => $game->important_notes ?? [],
            'delivery_mode' => $game->delivery_mode,
            'delivery_subtext' => $game->effectiveDeliverySubtext(),
            'seo_title' => $game->seo_title,
            'seo_title_local' => $game->seo_title_local,
            'seo_description' => $game->seo_description,
            'seo_description_local' => $game->seo_description_local,
            'seo_keywords' => $game->seo_keywords,
            'seo_og_image' => $game->seo_og_image,
            'schema_brand' => $game->schema_brand,
            'schema_category' => $game->schema_category,
            'no_index' => $game->no_index,
        ];
    }

    /**
     * ADR-027's 2026-08-29 addendum, decisions 20/21: `member_price_sen`
     * reflects the caller's own tier when `$isPersonalized`, otherwise
     * the best-value tier (highest discount_percent) as an anonymous
     * anchor — and is omitted entirely, never `null`, when the kill
     * switch is off or no membership tier is configured, matching this
     * codebase's narrow-public-response-shape discipline (backend/AGENTS.md).
     *
     * @return array<string, mixed>
     */
    private function publicPackage(Package $package, ?MembershipPlan $anchorPlan, bool $isPersonalized = false): array
    {
        $data = [
            'id' => $package->id,
            'name' => $package->name,
            'selling_price_sen' => $this->sellingPriceSen($package),
            'has_denomination' => $package->denomination !== null,
            'has_catalog_code' => $package->catalog_code !== null,
        ];

        if ($anchorPlan !== null) {
            $data['member_price_sen'] = $this->membershipPricing->calculateMemberPrice(
                $package->cost_price,
                (float) $package->markup_percent,
                (float) $anchorPlan->discount_percent,
            );

            // Only true when `anchorPlan` is this specific caller's own
            // resolved tier (a valid session token) — never for the
            // anonymous "best tier" anchor. The storefront uses this to
            // decide whether member_price_sen is safe to treat as the
            // customer's real payable total before payment, or only a
            // display-only savings hint (PackageGrid's own comment).
            if ($isPersonalized) {
                $data['member_price_personalized'] = true;
            }
        }

        return $data;
    }

    /**
     * ADR-034: among active packages sharing a (game_id, denomination)
     * equivalence key, keep only the cheapest — the same product sold
     * by two suppliers must never let the client pick the pricier one
     * (ORD-9, price is always server-computed).
     *
     * ADR-075's catalog-code addendum (2026-09-04) extends this with a
     * second, independent equivalence key, `catalog_code` — the same
     * dedup rule applied to bundle/pass packages (which never carry a
     * `denomination`, ADR-034), grouped separately so the two key
     * spaces can never collide with each other. A package with
     * neither key set is never grouped — it stays its own row,
     * matching ADR-034's original "never dedup null" intent.
     *
     * @param  Collection<int, Package>  $packages
     * @return Collection<int, Package>
     */
    private function dedupByDenomination($packages)
    {
        [$withDenomination, $rest] = $packages->partition(
            fn (Package $package) => $package->denomination !== null,
        );
        [$withCatalogCode, $withoutEither] = $rest->partition(
            fn (Package $package) => $package->catalog_code !== null,
        );

        $cheapestPerDenomination = $withDenomination
            ->groupBy('denomination')
            ->map(fn ($group) => $this->cheapestInGroup($group));

        $cheapestPerCatalogCode = $withCatalogCode
            ->groupBy('catalog_code')
            ->map(fn ($group) => $this->cheapestInGroup($group));

        return $withoutEither
            ->concat($cheapestPerDenomination->values())
            ->concat($cheapestPerCatalogCode->values())
            ->sortBy([
                // ADR-034 follow-up (founder feedback, 2026-08-25):
                // smallest denomination first reads as cheapest-first
                // to a customer, sorting numerically rather than
                // alphabetically-by-name. Packages without a curated
                // denomination (including every catalog_code-deduped
                // bundle/pass) sort last, by name — same ordering
                // GameController::packages() already applies admin-side.
                fn (Package $a, Package $b) => ($a->denomination === null ? 1 : 0) <=> ($b->denomination === null ? 1 : 0),
                fn (Package $a, Package $b) => $a->denomination <=> $b->denomination,
                fn (Package $a, Package $b) => strcmp($a->name, $b->name),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, Package>  $group
     */
    private function cheapestInGroup($group): Package
    {
        return $group
            ->sortBy([
                fn (Package $a, Package $b) => $this->sellingPriceSen($a) <=> $this->sellingPriceSen($b),
                fn (Package $a, Package $b) => $a->id <=> $b->id,
            ])
            ->first();
    }

    /**
     * ADR-060 PR-4c: the customer-facing price for the `Host`-resolved
     * storefront brand. `calculateForAffiliate` with a null
     * `wholesaleTierMarkupPct()` (primary brand / lapsed tier) is
     * byte-identical to the old `calculate()` — the primary storefront's
     * listing does not shift by a sen (ADR-060 PR-4b).
     */
    private function sellingPriceSen(Package $package): int
    {
        $brand = $this->storefrontBrand->get();

        return $this->pricing->calculateForAffiliate(
            $package->cost_price,
            $package->standard_selling_price,
            $brand->wholesaleTierMarkupPct(),
            (float) $brand->markup_pct,
        )->sellingPrice;
    }

    /**
     * ADR-014 invalidation seam, extended: GameController::
     * forgetIndexCache()/forgetPackagesCache() already run at every
     * write that changes what these listings show — this just adds
     * the public cache keys to those same, single choke points rather
     * than scattering new invalidation calls across GameController/
     * PackageController/SupplierProductController.
     *
     * ADR-071 PR2: these choke points also purge the storefront's
     * Next.js `catalog` Data-Cache tag (`NextRevalidation::purge()`).
     *
     * ADR-060 PR-4c: the index listing is cached per storefront brand
     * (`price_from_sen` varies by the brand's wholesale tier + markup).
     * The default `database` cache store has no tag support, so a single
     * price/game edit clears every brand's entry by looping the ids —
     * a handful of `forget()`s on a rare admin write, not a hot path.
     *
     * ADR-060 PR-6: a per-brand config change (an affiliate's catalog
     * toggle, markup, or branding save) knows its own brand id and
     * passes it — only that brand's entry is cleared, not all of them.
     */
    public static function forgetIndexCache(?int $brandId = null): void
    {
        if ($brandId !== null) {
            Cache::tags(["catalog.index.brand.{$brandId}"])->flush();
        } else {
            Cache::tags(['catalog.index'])->flush();
        }

        // ADR-077 PR-5: the reseller API/Bot "price list"
        // (ResellerCatalogService::listAvailable(), 60s cache) is
        // brand- and tier-independent — one global key — but it shows the
        // same packages this index does, so it goes stale on the same
        // writes. Every catalog write path (package edit, game edit,
        // price sync, supplier bulk-deactivate) already funnels through
        // here, so this one forget covers all of them; the brand id is
        // irrelevant to it.
        Cache::forget(ResellerCatalogService::CACHE_KEY);

        // A1 hardening (2026-09-10 reseller-family audit): the per-tier
        // priced catalog built on top of listAvailable() above goes stale
        // on the same writes — flush every tier's cached entry at once.
        ResellerApi\CatalogController::forgetPricedCache();

        NextRevalidation::purge();
    }

    public static function forgetPackagesCache(int $gameId, bool $withIndex = true): void
    {
        // ADR-027's 2026-08-29 addendum, decision 18: this cache moved to
        // its own scoped, tagged store (config('cache.catalog_packages_store'))
        // — a plain Cache::forget() on the default store (or even the
        // right store without the same ->tags() call) would silently
        // miss it, since a tagged entry's real storage key is namespaced
        // by the tag, not the same key Cache::forget() would look up bare.
        //
        // A single package/price edit can change the anonymous anchor
        // key AND every per-tier personalized key for this game at once
        // (member pricing is derived from the same package row) — flush
        // the whole per-game tag rather than a single forget(key) call,
        // so every cached variant for this game is covered, not just
        // the anonymous one.
        // The per-game tag covers every brand's entry at once — brand is
        // in the cache KEY, not the tag set, so one flush per game still
        // reaches all of them (ADR-060 PR-4c).
        Cache::store(config('cache.catalog_packages_store'))
            ->tags(["catalog.packages.game.{$gameId}"])
            ->flush();

        if ($withIndex) {
            // A package price/status change also changes the index's
            // per-game price_from_sen — the index cache must go too.
            self::forgetIndexCache();
        }
    }

    /**
     * Decision 18: a membership_plans edit (or the kill switch) changes
     * every game's packages at once, unlike a single package/markup
     * edit — one tagged flush instead of looping every Game ID.
     */
    public static function forgetPackagesCacheForMembership(): void
    {
        Cache::store(config('cache.catalog_packages_store'))->tags(['catalog.packages'])->flush();
        NextRevalidation::purge();
    }

    /**
     * ADR-060 PR-6: an affiliate's own `markup_pct` change re-prices
     * every package on THEIR storefront and nothing else — flush only
     * this brand's per-brand tag (+ its index entry), never the whole
     * `catalog.packages` tag.
     */
    public static function forgetCacheForBrand(int $brandId): void
    {
        Cache::store(config('cache.catalog_packages_store'))
            ->tags(["catalog.packages.brand.{$brandId}"])
            ->flush();
        self::forgetIndexCache($brandId);
    }

    private static function indexCacheKey(int $brandId): string
    {
        return "catalog.public.games.index.brand.{$brandId}";
    }

    private static function packagesCacheKey(int $gameId, ?int $membershipPlanId, int $brandId): string
    {
        $key = "catalog.public.games.{$gameId}.brand.{$brandId}.packages";

        return $membershipPlanId !== null ? "{$key}.tier.{$membershipPlanId}" : $key;
    }
}
