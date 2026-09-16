import type { NextConfig } from "next";

/**
 * `next/image` refuses any remote host not listed here (HTTP 400 from
 * the optimizer, silent broken <img> on the page). Admin-uploaded
 * images (hero slides, game thumbnails, gallery) are served from the
 * backend's `/storage/**`, so the backend host — whatever
 * NEXT_PUBLIC_API_URL points at — must be allowed, alongside the local
 * dev hosts.
 */
const apiHost = process.env.NEXT_PUBLIC_API_URL
  ? new URL(process.env.NEXT_PUBLIC_API_URL).hostname
  : null;

/**
 * ADR-101 decision 8 (narrowed, see the ADR's 2026-09-16 addendum 3):
 * a static `headers()` CSP, not a per-request nonce — a nonce forces
 * every page to render dynamically (Next's own bundled CSP guide),
 * which would reverse ADR-071 PR1's whole point (removing
 * `force-dynamic` from this same root layout so pages stay
 * static/ISR-cached). Decision 9 already removed the one known
 * inline-script-injection vector (the admin free-text SeoScript
 * field), so a source allowlist alone — no `strict-dynamic`/nonce
 * needed — already delivers the report's real ask (block an
 * unauthorized script *origin*, the Magecart/skimmer case).
 * `'unsafe-inline'` stays on script-src/style-src because Next's own
 * hydration payload, the JSON-LD/theme-preset inline tags
 * (`app/layout.tsx`), and the FB/TikTok pixel init snippets are all
 * inline by construction — none of them are the threat this CSP
 * exists to stop, an unrecognised *source* is.
 */
const cspDirectives = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://connect.facebook.net https://analytics.tiktok.com`,
  "style-src 'self' 'unsafe-inline'",
  `img-src 'self' data: blob: https://cdn.pekangame.space${apiHost ? ` https://${apiHost}` : ""}`,
  "font-src 'self'",
  `connect-src 'self'${apiHost ? ` https://${apiHost}` : ""} https://www.google-analytics.com https://analytics.google.com https://analytics.tiktok.com`,
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'self'",
  "upgrade-insecure-requests",
].join("; ");

const nextConfig: NextConfig = {
  async headers() {
    return [
      {
        source: "/(.*)",
        headers: [{ key: "Content-Security-Policy", value: cspDirectives }],
      },
    ];
  },
  images: {
    remotePatterns: [
      ...(apiHost
        ? ([{ protocol: "https" as const, hostname: apiHost, pathname: "/storage/**" }])
        : []),
      {
        protocol: "https",
        hostname: "kedairuncit-backend.test",
        port: "",
        pathname: "/storage/**",
      },
      {
        protocol: "http",
        hostname: "127.0.0.1",
        port: "8000",
        pathname: "/storage/**",
      },
      // ADR-095 — gallery/logo/hero images served from R2 via this
      // custom domain once GALLERY_DISK=r2_gallery; unrelated to the
      // apiHost pattern above, which only ever covers /storage/**.
      {
        protocol: "https",
        hostname: "cdn.pekangame.space",
      },
    ],
    // Herd's *.test domains resolve to 127.0.0.1 — Next.js's upstream-image
    // SSRF guard blocks any private-IP resolution by default. Dev-only: a
    // production backend host won't resolve to a private IP, so this stays
    // off in production builds rather than blanket-disabling the guard.
    dangerouslyAllowLocalIP: process.env.NODE_ENV !== "production",
  },
};

export default nextConfig;
