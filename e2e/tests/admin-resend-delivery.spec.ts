import { test, expect } from "@playwright/test";
import { E2E_RESEND_FIXTURE_ORDER_NUMBER } from "./constants";
import { loginAsAdmin, openOrder } from "./helpers";

// Golden path — ADR-023. Covers ORD-7/ADR-017's Resend Delivery — one
// of only two ways ADR-004 lets a stuck/failed order ever get fixed.
// Gamevion is faked to always succeed (APP_ENV=e2e binding, ADR-023
// decision #6), so the fixture order should reach "delivered".
test("admin login -> failed order -> Resend Delivery", async ({ page }) => {
  await loginAsAdmin(page);
  await openOrder(page, E2E_RESEND_FIXTURE_ORDER_NUMBER);

  await expect(page.getByText("delivery: failed")).toBeVisible();

  await page.getByRole("button", { name: "Resend Delivery…" }).click();

  // The modal defaults to the order's own (still-active) package —
  // wait for that auto-selection before submitting, matching
  // ResendDeliveryModal's real canSubmit gate. #resend_package is now
  // PrimeReact's SimpleSelect (ADR-038) — a composed trigger button,
  // not a native <select>, so there's no .value to assert on; check
  // the trigger's displayed label text instead.
  const packageSelect = page.locator("#resend_package");
  await expect(packageSelect).not.toHaveText("");

  await page.getByRole("button", { name: "Retry Delivery" }).click();

  await expect(page.getByText(/Resend queued/)).toBeVisible();

  // FulfillOrderJob runs on the real `orders` queue (boot-backend.sh) —
  // poll by reopening the order rather than assuming instant completion.
  await expect(async () => {
    await openOrder(page, E2E_RESEND_FIXTURE_ORDER_NUMBER);
    await expect(page.getByText("delivery: delivered")).toBeVisible();
  }).toPass({ timeout: 30_000 });
});
