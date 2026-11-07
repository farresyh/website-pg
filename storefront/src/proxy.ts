import { NextResponse } from "next/server";
import type { NextFetchEvent, NextRequest } from "next/server";
import { createStaleCache } from "@/lib/stale-cache";

/**
 * ADR-029 decision 3/9 — redirect execution. Next.js 16 deprecated and
 * renamed the `middleware.ts` file convention to `proxy.ts` (confirmed
 * against this project's own bundled docs, per storefront/AGENTS.md's
 * warning not to assume conventions from training data) — this file
 * (not middleware.ts) is where that lookup lives. Proxy defaults to
 * the Node.js runtime in this version, so this module-level cache
 * persists across requests within one server process.
 */

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * ADR-078 2026-11-08 addendum: this file runs before every page, so nothing
 * here may make a visitor wait for the API once it holds an answer. A redirect
 * or a host verdict older than 15s is served while a new one loads behind it.
 *
 * The 15s stays because there is no cross-instance invalidation for this
 * module cache (ADR-078 decision 2). Next's Data Cache can't help: fetch cache
 * options have no effect in Proxy.
 */
const FRESH_MS = 15_000;

/** After a quiet hour the old answer isn't trusted, and one reader waits. */
const STALE_LIMIT_MS = 3_600_000;

/** Only an empty cache waits on this; past it the page is served, as on any failed read. */
const API_TIMEOUT_MS = 2_000;

/**
 * ADR-078 decision 2 — the primary storefront hostname(s). A request on
 * one of these is a known host with no backend round-trip: only a
 * third-party custom domain (ADR-060) needs `/api/catalog/storefront-status`.
 * Comma-separated, lower-case, no scheme; must stay in sync with the
 * backend's `STOREFRONT_PRIMARY_HOSTS`.
 */
const PRIMARY_HOSTS = new Set(
  (process.env.NEXT_PUBLIC_PRIMARY_HOSTS ?? "")
    .split(",")
    .map((h) => h.trim().toLowerCase())
    .filter(Boolean),
);

interface RedirectRule {
  from_path: string;
  to_path: string;
  status_code: 301 | 302;
}

type Background = (work: Promise<unknown>) => void;

const redirectRules = createStaleCache<Map<string, RedirectRule>>({ freshMs: FRESH_MS, staleLimitMs: STALE_LIMIT_MS });

/**
 * ADR-060 PR-5 — a custom domain that is not attached to any active
 * affiliate (never verified, suspended, removed) must show a hard "store
 * unavailable" page, never the fallback storefront. `ResolveStorefrontBrand`
 * on the backend answers `/api/catalog/storefront-status` with a coded
 * 404 for such a host; this caches that verdict per host. The backend still
 * refuses such a host on every API call, so a stale verdict shows at most a
 * page that can't sell.
 */
const hostVerdicts = createStaleCache<boolean>({ freshMs: FRESH_MS, staleLimitMs: STALE_LIMIT_MS });

async function loadHostVerdict(host: string): Promise<boolean> {
  try {
    const res = await fetch(`${API_BASE_URL}/api/catalog/storefront-status`, {
      headers: { Accept: "application/json", "X-Storefront-Host": host },
      signal: AbortSignal.timeout(API_TIMEOUT_MS),
    });
    // Only a *coded* 404 is a definitive "unknown host". A network blip
    // or any other status keeps serving (degrade gracefully).
    if (res.status === 404) {
      const body = await res.json().catch(() => null);
      return body?.code !== "unknown_storefront_host";
    }
  } catch {
    // Fall through: the same degrade as above.
  }

  return true;
}

async function isKnownHost(host: string, background: Background): Promise<boolean> {
  // The primary storefront never needs the backend host check (ADR-078
  // decision 2). Strip any port before matching (local dev sends `host:3001`).
  if (PRIMARY_HOSTS.has(host.toLowerCase().split(":")[0])) {
    return true;
  }

  return hostVerdicts.get(host, () => loadHostVerdict(host), background);
}

async function loadRedirectRules(previous = new Map<string, RedirectRule>()): Promise<Map<string, RedirectRule>> {
  try {
    const response = await fetch(`${API_BASE_URL}/api/catalog/seo/redirects`, {
      headers: { Accept: "application/json" },
      signal: AbortSignal.timeout(API_TIMEOUT_MS),
    });
    if (!response.ok) return previous;

    const rules: RedirectRule[] = await response.json();
    return new Map(rules.map((r) => [r.from_path, r]));
  } catch {
    // Backend unreachable — keep serving whatever was last cached.
    return previous;
  }
}

function recordHit(fromPath: string): void {
  fetch(`${API_BASE_URL}/api/catalog/seo/redirects/record-hit`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ from_path: fromPath }),
  }).catch(() => {
    // Fire-and-forget (addendum 2 decision 15) — a failed hit-count write never blocks the redirect itself.
  });
}

export async function proxy(request: NextRequest, event: NextFetchEvent) {
  const { pathname } = request.nextUrl;
  const background: Background = (work) => event.waitUntil(work);
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host") ?? "";

  // ADR-060 PR-5 — an unrecognised custom domain gets the hard
  // "store unavailable" page, not the fallback storefront. Skipped for
  // the page itself (avoid a rewrite loop).
  const checkHost = host !== "" && pathname !== "/store-unavailable";

  const [known, rules] = await Promise.all([
    checkHost ? isKnownHost(host, background) : true,
    redirectRules.get("rules", loadRedirectRules, background),
  ]);

  // ADR-120 decision 14: a real 503 (what the page itself says), not a 200 a
  // crawler could index.
  if (!known) {
    return NextResponse.rewrite(new URL("/store-unavailable", request.url), { status: 503 });
  }

  const rule = rules.get(pathname);

  if (rule) {
    recordHit(pathname);
    return NextResponse.redirect(new URL(rule.to_path, request.url), rule.status_code);
  }

  return NextResponse.next();
}

/** `images/` is `public/`: a static file needs neither check (ADR-078 2026-11-08 addendum). */
export const config = {
  matcher: ["/((?!api|_next/static|_next/image|images/|favicon.ico|sitemap.xml|robots.txt).*)"],
};
