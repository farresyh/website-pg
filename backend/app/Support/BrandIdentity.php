<?php

namespace App\Support;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;

/**
 * The brand a customer sees in a message — the one lookup shared by
 * membership email (`PlunkMailer`) and WhatsApp (`CustomerNotificationService`),
 * which used to resolve name and link separately (ADR-068 2026-10-13
 * addendum, decision 13).
 *
 * Name falls back the way the storefront header does (`BrandingController`):
 * store name, then business name. `origin` is `Affiliate::canonicalOrigin()`
 * (ADR-120). `AffiliateBranding` carries the tenant scope, so it is read
 * without it — callers run outside the affiliate guard (queue jobs).
 */
final readonly class BrandIdentity
{
    public function __construct(
        public string $name,
        public ?string $logoUrl,
        public string $origin,
    ) {}

    public static function of(?int $affiliateId): self
    {
        $affiliate = $affiliateId === null ? null : Affiliate::withTrashed()->find($affiliateId);
        $branding = $affiliate === null
            ? null
            : AffiliateBranding::withoutAffiliateScope()->where('affiliate_id', $affiliate->id)->first();

        return new self(
            name: $branding?->store_name ?: ($affiliate?->business_name ?: 'PekanGame'),
            logoUrl: $branding?->logo_url,
            origin: $affiliate?->canonicalOrigin() ?? rtrim((string) config('services.storefront.url'), '/'),
        );
    }

    public function host(): string
    {
        return (string) parse_url($this->origin, PHP_URL_HOST);
    }
}
