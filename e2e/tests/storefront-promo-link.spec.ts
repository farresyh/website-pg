import { test, expect, type Page } from "@playwright/test";
import { STOREFRONT_URL, E2E_GAME_SLUG } from "./constants";

// ADR-118 2026-11-07 addendum. A KOL link says the code is saved, and the
// code applies itself once the contact details are in, so a customer can't
// pay the full price by missing an Apply button.
async function openReview(page: Page) {
  await page.goto(`${STOREFRONT_URL}/order/${E2E_GAME_SLUG}`);
  await page.locator("#playerId").fill(String(910000000 + Math.floor(Math.random() * 99999)));
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await page.getByRole("button", { name: "E2E Test Package" }).click();
  await page.getByRole("button", { name: "Maybank2u" }).click();
  await page.getByRole("button", { name: /Review & Pay/ }).click();
  await expect(page.locator("#reviewEmail")).toBeVisible();
}

async function fillContact(page: Page, email: string) {
  await page.locator("#reviewEmail").fill(email);
  await page.locator("#reviewName").fill("Promo Link Tester");
  await page.locator("#reviewPhone").fill("0123456700");
}

test("a KOL link announces its code and applies it at checkout", async ({ page }) => {
  test.slow();
  await page.setViewportSize({ width: 390, height: 844 });

  await page.goto(`${STOREFRONT_URL}/?promo=e2ekol`);
  await expect(page.getByText("Promo code E2EKOL saved.")).toBeVisible();

  await openReview(page);
  await expect(page.locator("#reviewPromo")).toHaveValue("E2EKOL");
  await expect(page.getByText("This applies once your email and phone number are in.")).toBeVisible();

  await fillContact(page, "promo-link@example.com");
  await expect(page.getByText(/You save RM/).first()).toBeVisible({ timeout: 10_000 });
  await expect(page.getByRole("button", { name: /Confirm & Pay RM5\.75/ })).toBeVisible();

  // Removing it is the customer's call: it stays off.
  await page.getByRole("button", { name: "Remove" }).click();
  await expect(page.getByRole("button", { name: /Confirm & Pay RM6\.00/ })).toBeVisible();
  await page.waitForTimeout(900);
  await expect(page.getByText(/You save RM/)).toHaveCount(0);
});

test("the note can be closed and stays closed", async ({ page }) => {
  await page.goto(`${STOREFRONT_URL}/?promo=E2EKOL`);
  await page.getByRole("button", { name: "Close" }).click();
  await expect(page.getByText("Promo code E2EKOL saved.")).toHaveCount(0);
  await page.reload();
  await expect(page.getByRole("heading").first()).toBeVisible();
  await expect(page.getByText("Promo code E2EKOL saved.")).toHaveCount(0);
});

test("no link, no note", async ({ page }) => {
  await page.goto(`${STOREFRONT_URL}/`);
  await expect(page.getByRole("heading").first()).toBeVisible();
  await expect(page.getByText(/Promo code .* saved\./)).toHaveCount(0);
});
