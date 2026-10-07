/**
 * Fallback site origin only — used when the backend is unreachable or
 * predates `canonical_origin`. Every absolute URL (canonical, sitemap,
 * robots, llms.txt, JSON-LD) comes from `getBranding().canonicalOrigin`,
 * the serving brand's own primary domain (ADR-120 decision 2); this
 * env value would put every affiliate's URLs on the platform domain.
 */
export const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";
