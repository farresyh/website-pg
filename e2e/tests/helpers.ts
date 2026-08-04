import type { Page } from "@playwright/test";
import { ADMIN_URL, E2E_ADMIN_EMAIL, E2E_ADMIN_PASSWORD } from "./constants";

/** Shared by both admin golden-path specs — not its own golden path (ADR-023 only names 3). */
export async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${ADMIN_URL}/login`);
  await page.locator("#email").fill(E2E_ADMIN_EMAIL);
  await page.locator("#password").fill(E2E_ADMIN_PASSWORD);
  await page.getByRole("button", { name: "Sign in" }).click();
  await page.waitForURL(`${ADMIN_URL}/admin`);
}

export async function openOrder(page: Page, orderNumber: string): Promise<void> {
  await page.goto(`${ADMIN_URL}/admin/orders`);
  await page.getByPlaceholder("Search order # or customer email…").fill(orderNumber);
  // Scoped to the matching row, not `.first()` — search is server-side
  // and re-fetches on every keystroke, so the table briefly still shows
  // the unfiltered list; waiting for the order_number's own row to
  // appear avoids a race where an unrelated row's "View" gets clicked.
  const row = page.locator("tr", { hasText: orderNumber });
  await row.getByRole("button", { name: "View" }).click();
}
