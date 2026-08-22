/**
 * Absolute site origin — needed for sitemap.ts/robots.ts/JSON-LD
 * (search engines need absolute URLs, unlike everywhere else in this
 * app which only ever needs relative paths). No production domain is
 * purchased yet (ADR-020 still design-only) — set NEXT_PUBLIC_SITE_URL
 * once one exists; falls back to localhost for local dev in the
 * meantime.
 */
export const SITE_URL = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";
