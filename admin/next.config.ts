import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // ADR-020 Phase 1 — required for the Docker multi-stage build to
  // produce a minimal runtime image (.next/standalone + .next/static).
  output: "standalone",
};

export default nextConfig;
