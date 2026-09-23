import { unstable_rethrow } from "next/navigation";

/**
 * Thin fetch wrapper around the Laravel backend API — mirrors
 * admin/src/lib/api-client.ts. All money/pricing logic lives in
 * Laravel (ORD-9): this client never computes or trusts a
 * price/fee/profit value itself, only forwards requests and surfaces
 * whatever the backend returns. Guest checkout (ADR-011) — no auth
 * token concept for most of this app; `token` below exists only for
 * ADR-027's membership session token (MembershipSessionTokenService),
 * a deliberately different, lighter mechanism than admin's Sanctum
 * Bearer token — see lib/membership-session.ts for why this is
 * localStorage, not admin's sessionStorage.
 */

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * ADR-060 PR-3: the storefront calls this backend directly from both the
 * browser and SSR, so the visitor's own domain (`shop.acme.com`) never
 * arrives as the HTTP `Host` — it is `api.pekangame.space` on every call.
 * We forward it in `X-Storefront-Host` (which `ResolveStorefrontBrand`
 * reads) and, for cached SSR reads, also as a `__brand` query param so
 * Next's Data Cache keys per brand even if it does not vary on headers.
 * The backend ignores `__brand`.
 *
 *  - browser: `window.location.host` (the address bar).
 *  - SSR:     the incoming request's forwarded host, via `next/headers`.
 *             Dynamically imported and guarded so this shared module
 *             never pulls `next/headers` into a client bundle. Reading it
 *             opts the route into dynamic rendering — inherent to a
 *             multi-tenant storefront.
 *  - build / no request scope: undefined — the backend falls back to
 *             `Affiliate::primary()`.
 */
async function resolveStorefrontHost(): Promise<string | undefined> {
  if (typeof window !== "undefined") {
    return window.location.host || undefined;
  }

  try {
    const { headers } = await import("next/headers");
    const h = await headers();
    return h.get("x-forwarded-host") ?? h.get("host") ?? undefined;
  } catch (err) {
    // `headers()` bails out of prerendering by throwing — let that
    // through so Next marks the route dynamic (a multi-tenant shell is
    // inherently per-request), rather than swallowing it and hanging
    // the build. A genuine non-request context (unit test, script)
    // falls through to `undefined` → the backend uses Affiliate::primary().
    unstable_rethrow(err);
    return undefined;
  }
}

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string | undefined,
    message: string,
    public retryAfter: string | null = null,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

interface RequestOptions extends Omit<RequestInit, "body"> {
  body?: unknown;
  token?: string;
  /** Next.js Data Cache config — see lib/cache.ts's `catalogCache` (ADR-071 PR1). */
  next?: { revalidate?: number | false; tags?: string[] };
}

export async function apiFetch<T>(path: string, { body, token, headers, ...init }: RequestOptions = {}): Promise<T> {
  const storefrontHost = await resolveStorefrontHost();

  let url = `${API_BASE_URL}${path}`;
  if (storefrontHost && init.next) {
    // Cached SSR read — pin the brand into the URL so each brand gets
    // its own Data Cache entry regardless of header-keying behaviour.
    url += (path.includes("?") ? "&" : "?") + "__brand=" + encodeURIComponent(storefrontHost);
  }

  const response = await fetch(url, {
    ...init,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(storefrontHost ? { "X-Storefront-Host": storefrontHost } : {}),
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    throw new ApiError(
      response.status,
      payload?.code,
      payload?.message ?? `Request to ${path} failed (${response.status})`,
      response.headers.get("Retry-After"),
    );
  }

  return payload as T;
}
