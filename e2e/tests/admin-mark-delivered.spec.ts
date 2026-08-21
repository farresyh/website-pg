import { test, expect } from "@playwright/test";
import { E2E_NEEDS_REVIEW_FIXTURE_ORDER_NUMBER } from "./constants";
import { loginAsAdmin, openOrder } from "./helpers";

// Golden path — ADR-023 Trigger A (a new order-resolution mechanism
// beyond retry-delivery/voucher). Covers ADR-026's `needs_review`
// state and its one non-retry exit, "Mark as Delivered" — an admin
// manually confirming a real Gamevion invoice number after cross-
// referencing Gamevion's own dashboard, closing the exact gap
// (`supplier_ref` never set) that made the order ambiguous.
test("admin login -> needs_review order -> Mark as Delivered", async ({ page }) => {
  await loginAsAdmin(page);
  await openOrder(page, E2E_NEEDS_REVIEW_FIXTURE_ORDER_NUMBER);

  await expect(page.getByText("delivery: needs_review")).toBeVisible();
  await expect(
    page.getByText("Delivery outcome is ambiguous — Gamevion never confirmed success or failure for this order."),
  ).toBeVisible();

  // ADR-026 decision 4c — Issue Voucher is deliberately never offered
  // from this state, the one deliberate non-exit this ADR exists for.
  await expect(page.getByRole("button", { name: "Issue Voucher…" })).toHaveCount(0);

  await page.getByRole("button", { name: "Mark as Delivered…" }).click();

  await page.locator("#mark_delivered_supplier_ref").fill("GV-E2E-CONFIRMED-INVOICE");
  // exact:true — same reasoning as the Issue Voucher spec's own
  // submit-button locator: the trigger button's accessible name
  // ("Mark as Delivered…") would otherwise substring-match this one,
  // since the underlying page stays mounted behind the modal overlay.
  await page.getByRole("button", { name: "Mark as Delivered", exact: true }).click();

  await expect(page.getByText("Delivery confirmed manually — ledger profit credited.")).toBeVisible();
  await expect(page.getByText("delivery: delivered")).toBeVisible();
});
