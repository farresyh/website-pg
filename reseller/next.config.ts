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
const apiHost = process.env.NEXT_PUBLIC_API_URL
  ? new URL(process.env.NEXT_PUBLIC_API_URL).hostname
  : null;

const cspDirectives = [
  "default-src 'self'",
  "script-src 'self' 'unsafe-inline'",
  "style-src 'self' 'unsafe-inline'",
  `img-src 'self' data: blob: https://cdn.pekangame.space${apiHost ? ` https://${apiHost}` : ""}`,
  "font-src 'self'",
  `connect-src 'self'${apiHost ? ` https://${apiHost}` : ""}`,
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
