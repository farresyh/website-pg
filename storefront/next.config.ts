import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  images: {
    remotePatterns: [
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
    ],
    // Herd's *.test domains resolve to 127.0.0.1 — Next.js's upstream-image
    // SSRF guard blocks any private-IP resolution by default. Dev-only: a
    // production backend host won't resolve to a private IP, so this stays
    // off in production builds rather than blanket-disabling the guard.
    dangerouslyAllowLocalIP: process.env.NODE_ENV !== "production",
  },
};

export default nextConfig;
