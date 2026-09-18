import { test, expect } from "@playwright/test";
import { E2E_VOUCHER_FIXTURE_ORDER_NUMBER } from "./constants";
import { loginAsAdmin, openOrder } from "./helpers";

// Golden path — ADR-023. Covers ADR-004's other resolution path
// (retry-delivery or voucher, never a cash refund) — the UI gap this
// same ADR's grilling session found and closed (VoucherController::storeFromOrder()
// was fully built/tested but had no admin button before this).
test("admin login -> failed order -> Issue Voucher", async ({ page }) => {
  await loginAsAdmin(page);
  await openOrder(page, E2E_VOUCHER_FIXTURE_ORDER_NUMBER);

  await expect(page.getByText("delivery: failed")).toBeVisible();

  await page.getByRole("button", { name: "Issue Voucher…" }).click();
  // exact:true — the trigger button's own accessible name ("Issue
  // Voucher…") would otherwise substring-match this same locator,
  // since the underlying page stays mounted behind the modal overlay.
  await page.getByRole("button", { name: "Issue Voucher", exact: true }).click();

  await expect(page.getByText(/Voucher .* issued\./)).toBeVisible();

  // The button is hidden once a voucher exists for this order — the
  // real guard this test proves, not just the happy-path submission.
  // ADR-102 decision 11: the old single-line "already issued for this
  // order" mention was replaced by RefundInformationCards' own card.
  // ADR-108 addendum (2026-09-18): that card's title is now the shared
  // "Refund Information" shell + a "Voucher" badge, not its own
  // "Compensation Voucher Issued" title.
  await expect(page.getByRole("button", { name: "Issue Voucher…" })).toHaveCount(0);
  await expect(page.getByText("Refund Information")).toBeVisible();
});
