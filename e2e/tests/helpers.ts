import type { Page } from "@playwright/test";
import { ADMIN_URL, E2E_ADMIN_EMAIL, E2E_ADMIN_PASSWORD } from "./constants";

/** Shared by both admin golden-path specs — not its own golden path (ADR-023 only names 3). */
export async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${ADMIN_URL}/login`);
  await page.locator("#email").fill(E2E_ADMIN_EMAIL);
  await page.locator("#password").fill(E2E_ADMIN_PASSWORD);
  await page.getByRole("button", { name: "Sign in" }).click();
  await page.waitForURL(`${ADMIN_URL}/admin`);
  // Found live, 2026-08-21, adding a 3rd admin spec: navigating to
  // /admin/orders immediately after landing on /admin can abort that
  // page's own in-flight GET /api/me (dashboard/page.tsx's session
  // verification) mid-flight. That's harmless on its own now (that
  // effect no longer clears the session on an aborted request — see
  // its own doc comment), but /admin/orders' page.tsx runs its own,
  // separate getClientSession() check on mount; under `next dev`
  // (Turbopack, uncompiled routes), the two navigations can land close
  // enough together that this second check sometimes reads before
  // everything from the first page has settled and bounces to /login
  // — reproduced deterministically by swapping which spec runs 2nd in
  // a 3-test serial run, independent of any one spec's own content.
  // This wait is a real synchronization point (network activity from
  // the dashboard actually settling), not an arbitrary sleep.
  await page.waitForLoadState("networkidle");
}

export async function openOrder(page: Page, orderNumber: string): Promise<void> {
  await page.goto(`${ADMIN_URL}/admin/orders`);
  // Same real synchronization point as loginAsAdmin's own wait, same
  // reason (`next dev`/Turbopack on-demand compilation timing, not an
  // arbitrary sleep) — found live 2026-08-28 adding new middleware
  // pages/nav entries shifted the admin app's compile graph just
  // enough to reproduce this spec's own version of the exact race
  // loginAsAdmin already documents: two `/admin/orders` search inputs
  // momentarily both in the DOM (a stale render lingering alongside
  // the settled one), tripping Playwright's strict-mode locator.
  await page.waitForLoadState("networkidle");
  await page.getByPlaceholder("Search order # or customer email…").fill(orderNumber);
  // Scoped to the matching row, not `.first()` — search is server-side
  // and re-fetches on every keystroke, so the table briefly still shows
  // the unfiltered list; waiting for the order_number's own row to
  // appear avoids a race where an unrelated row's "View" gets clicked.
  const row = page.locator("tr", { hasText: orderNumber });
  await row.getByRole("button", { name: "View" }).click();
}
