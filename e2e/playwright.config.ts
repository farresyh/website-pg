import { defineConfig, devices } from "@playwright/test";
import path from "node:path";
import { BACKEND_URL, ADMIN_URL, STOREFRONT_URL } from "./tests/constants";

const ROOT_DIR = path.resolve(__dirname, "..");

/**
 * ADR-023 — the 3 golden-path tests only, Chromium only (decision #1).
 * `webServer` boots all three real apps (not mocks of our own code —
 * only Gamevion is faked, at the backend container-binding level, see
 * boot-backend.sh) against a fresh, seeded, throwaway sqlite DB.
 */
export default defineConfig({
  testDir: "./tests",
  fullyParallel: false,
  // Forced serial, not just fullyParallel:false — found running the 2
  // admin specs concurrently: both logins can land in the same
  // `throttle:5,1` window on POST /api/login, rate-limiting one of
  // them. All 3 golden paths also share one backend + one sqlite file,
  // so serial execution avoids that entire class of cross-test
  // contention rather than working around it test-by-test.
  workers: 1,
  retries: 0,
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
