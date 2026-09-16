import type { NextConfig } from "next";

/**
 * ADR-101 decision 8 (narrowed — see docs/adr.md's 2026-09-16 addendum 3,
 * `storefront/next.config.ts` carries the full reasoning). No nonce (would
 * force dynamic rendering app-wide for no real benefit here — this app
 * loads no third-party script host at all). `'unsafe-inline'` stays for
 * script-src/style-src — Next's own hydration payload is inline by
 * construction; nothing in this app injects admin- or user-controlled
 * script content.
 */
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
        headers: [{ key: "Content-Security-Policy", value: cspDirectives }],
      },
    ];
  },
};

export default nextConfig;
