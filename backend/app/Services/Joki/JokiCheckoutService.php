<?php

namespace App\Services\Joki;

use App\Models\Affiliate;
use App\Models\JokiSetting;
use App\Models\Package;
use App\Services\Pricing\OrderPricingResolver;
use Illuminate\Validation\ValidationException;

/**
 * ADR-126 addendum decisions 1, 7, 8: everything joki adds to the one
 * storefront checkout. It checks the brand may sell joki (both switches,
 * owned brand), prices the selection through JokiPricingService, and
 * resolves it on the Fixed basis at the payout share in force now, which
 * the order keeps (decision 7). CheckoutController calls it for checkout
 * and for the review total, so the two never disagree.
 */
final class JokiCheckoutService
{
    public function __construct(
        private readonly JokiPricingService $pricing,
        private readonly OrderPricingResolver $resolver,
    ) {}

    /** @param  array<string, mixed>  $input  the request's validated `joki` block */
    public function resolve(Package $package, array $input, Affiliate $brand): JokiPurchase
    {
        $settings = JokiSetting::current();

        if (! $brand->jokiEnabledEffective($settings)) {
            throw ValidationException::withMessages(['package_id' => ['Joki is not available on this store.']]);
        }

        $role = filled($input['role'] ?? null) ? (string) $input['role'] : null;
        if ($role !== null && ! in_array($role, $settings->roles ?? [], true)) {
            throw ValidationException::withMessages(['joki.role' => ['Choose a role from the list.']]);
        }

        $quote = $this->pricing->quote($package, (array) ($input['from'] ?? []), (array) ($input['to'] ?? []), (bool) ($input['is_express'] ?? false));

        return new JokiPurchase(
            quote: $quote,
            pricing: $this->resolver->resolveFixed($quote->sellingSen(), $settings->payout_share_pct),
            payoutSharePct: $settings->payout_share_pct,
            heroRequest: filled($input['hero_request'] ?? null) ? trim((string) $input['hero_request']) : null,
            role: $role,
            playWindow: filled($input['play_window'] ?? null) ? trim((string) $input['play_window']) : null,
            termsSnapshot: $settings->terms_html,
        );
    }
}
