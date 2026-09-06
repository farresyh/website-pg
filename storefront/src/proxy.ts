import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";

/**
 * ADR-029 decision 3/9 — redirect execution. Next.js 16 deprecated and
 * renamed the `middleware.ts` file convention to `proxy.ts` (confirmed
 * against this project's own bundled docs, per storefront/AGENTS.md's
 * warning not to assume conventions from training data) — this file
 * (not middleware.ts) is where that lookup lives. Proxy defaults to
 * the Node.js runtime in this version, so this module-level cache
 * persists across requests within one server process, matching
 * decision 9's "cache-miss costs one lookup, every other request in
 * that window costs nothing" design.
 */

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";
const CACHE_TTL_MS = 60_000;

interface RedirectRule {
  from_path: string;
  to_path: string;
  status_code: 301 | 302;
}

let cachedRules: Map<string, RedirectRule> | null = null;
let cachedAt = 0;

/**
 * ADR-060 PR-5 — a custom domain that is not attached to any active
 * affiliate (never verified, suspended, removed) must show a hard "store
 * unavailable" page, never the fallback storefront. `ResolveStorefrontBrand`
 * on the backend answers `/api/catalog/storefront-status` with a coded
 * 404 for such a host; this caches that verdict per host so it costs one
 * request per host per minute, not one per page.
 */
const hostStatus = new Map<string, { known: boolean; at: number }>();

async function isKnownHost(host: string): Promise<boolean> {
  const cached = hostStatus.get(host);
  if (cached && Date.now() - cached.at < CACHE_TTL_MS) {
    return cached.known;
  }

  let known = true;
  try {
    const res = await fetch(`${API_BASE_URL}/api/catalog/storefront-status`, {
      headers: { Accept: "application/json", "X-Storefront-Host": host },
    });
    // Only a *coded* 404 is a definitive "unknown host". A network blip
    // or any other status keeps serving (degrade gracefully).
    if (res.status === 404) {
      const body = await res.json().catch(() => null);
      known = body?.code !== "unknown_storefront_host";
    }
  } catch {
    known = true;
  }

  hostStatus.set(host, { known, at: Date.now() });
  return known;
}

async function getRedirectRules(): Promise<Map<string, RedirectRule>> {
  const now = Date.now();
  if (cachedRules && now - cachedAt < CACHE_TTL_MS) {
    return cachedRules;
  }

  try {
    const response = await fetch(`${API_BASE_URL}/api/catalog/seo/redirects`, {
      headers: { Accept: "application/json" },
    });
    const rules: RedirectRule[] = response.ok ? await response.json() : [];
    cachedRules = new Map(rules.map((r) => [r.from_path, r]));
    cachedAt = now;
  } catch {
    // Backend unreachable — keep serving whatever was last cached (or
    // an empty map on a true cold start) rather than failing every request.
    cachedRules = cachedRules ?? new Map();
    cachedAt = now;
  }

  return cachedRules;
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

export async function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;

  // ADR-060 PR-5 — an unrecognised custom domain gets the hard
  // "store unavailable" page, not the fallback storefront. Skipped for
  // the page itself (avoid a rewrite loop).
  if (pathname !== "/store-unavailable") {
    const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host") ?? "";
    if (host && !(await isKnownHost(host))) {
      return NextResponse.rewrite(new URL("/store-unavailable", request.url));
    }
  }

  const rules = await getRedirectRules();
  const rule = rules.get(pathname);

  if (rule) {
    recordHit(pathname);
    return NextResponse.redirect(new URL(rule.to_path, request.url), rule.status_code);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!api|_next/static|_next/image|favicon.ico|sitemap.xml|robots.txt).*)"],
};
