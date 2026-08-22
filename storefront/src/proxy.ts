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
