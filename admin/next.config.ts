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

const nextConfig: NextConfig = {
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
