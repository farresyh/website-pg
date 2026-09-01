import { defineConfig, devices } from "@playwright/test";
import path from "node:path";
import { BACKEND_URL, ADMIN_URL, STOREFRONT_URL } from "./tests/constants";

const ROOT_DIR = path.resolve(__dirname, "..");

/**
 * ADR-023 — the original 3 golden-path tests, Chromium only (decision
 * #1), plus a 4th added 2026-08-21 under decision #2's Trigger A (a
 * new order-resolution mechanism beyond retry-delivery/voucher —
 * ADR-026's `needs_review`/Mark as Delivered). `webServer` boots all
 * three real apps (not mocks of our own code — only Gamevion is
 * faked, at the backend container-binding level, see
 * boot-backend.sh) against a fresh, seeded, throwaway sqlite DB.
 */
export default defineConfig({
  testDir: "./tests",
  fullyParallel: false,
  // Forced serial, not just fullyParallel:false — found running the
  // admin specs concurrently: both logins can land in the same
  // `throttle:5,1` window on POST /api/login, rate-limiting one of
  // them. All golden paths also share one backend + one sqlite file,
  // so serial execution avoids that entire class of cross-test
  // contention rather than working around it test-by-test.
  workers: 1,
  retries: 0,
  // Per-test cap. The default 30s is too tight for the checkout golden
  // path specifically: it's a ~10-step flow, and its last step is the
  // first hit to the storefront's `/order/status/[orderNumber]` route,
  // which `next dev` compiles on demand — 5-15s of cold-compile on a
  // 2-core CI runner, on top of the queue worker picking up
  // FulfillOrderJob and a 5s status-page poll cycle. 60s absorbs that
  // without masking a real hang (a genuinely stuck fulfilment still
  // fails, just later). The 3 admin specs finish in 4-9s — headroom is
  // free for them.
  timeout: 60_000,
  reporter: [["list"]],
  use: {
    trace: "retain-on-failure",
  },
  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
  ],
  webServer: [
    {
      command: "bash ./scripts/boot-backend.sh",
      cwd: __dirname,
      url: `${BACKEND_URL}/api/health`,
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      command: "npx next dev --port 3000",
      cwd: path.join(ROOT_DIR, "admin"),
      url: ADMIN_URL,
      reuseExistingServer: false,
      timeout: 60_000,
      env: { NEXT_PUBLIC_API_URL: BACKEND_URL },
    },
    {
      command: "npx next dev --port 3001",
      cwd: path.join(ROOT_DIR, "storefront"),
      url: STOREFRONT_URL,
      reuseExistingServer: false,
      timeout: 60_000,
      env: { NEXT_PUBLIC_API_URL: BACKEND_URL },
    },
  ],
});
