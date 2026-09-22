import type { NextConfig } from "next";

/**
 * `next/image` refuses any remote host not listed here (HTTP 400 from
 * the optimizer, silent broken <img> on the page). Admin-uploaded
 * images (hero slides, game thumbnails, gallery) are served from the
 * backend's `/storage/**`, so the backend host — whatever
 * NEXT_PUBLIC_API_URL points at — must be allowed, alongside the local
 * dev hosts. Mirrors `storefront/next.config.ts`.
 */
const apiHost = process.env.NEXT_PUBLIC_API_URL
  ? new URL(process.env.NEXT_PUBLIC_API_URL).hostname
  : null;

/**
 * The CSP below needs the real scheme, not an assumed `https://` — e2e's
 * throwaway backend and local dev's Herd/`php artisan serve` both run on
 * plain `http://`. Hardcoding `https://` silently CSP-blocks every
 * browser fetch to the backend on a non-https `NEXT_PUBLIC_API_URL` —
 * found live via the e2e suite (PR #224).
 */
const apiOrigin = process.env.NEXT_PUBLIC_API_URL
  ? new URL(process.env.NEXT_PUBLIC_API_URL).origin
  : null;

/**
 * ADR-101 decision 8 (narrowed — see docs/adr.md's 2026-09-16 addendum
 * 3 on `storefront/next.config.ts`, same reasoning here). No nonce (it
 * would force dynamic rendering app-wide for no real benefit — this app
 * loads no third-party script host at all, so `script-src` needs no
 * allowlist entries beyond `'self'`). `'unsafe-inline'` stays for
 * script-src/style-src — Next's own hydration payload is inline by
 * construction; nothing here injects admin- or user-controlled script
 * content (SeoScript, the one path that did, is removed by decision 9).
 */
// React needs eval() in development mode for stack-trace reconstruction
// (Next's own CSP guide) — never used in production, so this only ever
// loosens dev/e2e, not the deployed policy.
const isDev = process.env.NODE_ENV !== "production";

const cspDirectives = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline'${isDev ? " 'unsafe-eval'" : ""}`,
  "style-src 'self' 'unsafe-inline'",
  `img-src 'self' data: blob: https://cdn.pekangame.space${apiOrigin ? ` ${apiOrigin}` : ""}`,
  "font-src 'self'",
  `connect-src 'self'${apiOrigin ? ` ${apiOrigin}` : ""}`,
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
        headers: [
          { key: "Content-Security-Policy", value: cspDirectives },
          { key: "X-Content-Type-Options", value: "nosniff" },
        ],
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
      // custom domain once GALLERY_DISK=r2_gallery.
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
