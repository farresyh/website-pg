import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // ADR-020 Phase 1 — a minimal runtime image (.next/standalone + .next/static)
  // for the Docker multi-stage build, matching admin/ and storefront/.
  output: "standalone",
};

export default nextConfig;
