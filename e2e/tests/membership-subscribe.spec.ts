import { test, expect } from "@playwright/test";
import { BACKEND_URL, STOREFRONT_URL, E2E_MEMBER_EMAIL, E2E_MEMBER_OTP } from "./constants";

const MEMBERSHIP_TOKEN_KEY = "krs_membership_token";

// Golden path — ADR-068. Self-serve membership subscription, driven
// through the real /membership subscribe UI: land as a verified member
// -> pick a tier -> pay -> membership active. Identity is obtained via
// the API (E2ESeeder plants a pre-verifiable OTP fixture; MAIL_MAILER=
// log in e2e leaves no inbox to read a live code from) and injected into
// localStorage — the OTP send/verify UI is Phase-5 code covered
// elsewhere, not this spec's concern. The payment layer is the
// APP_ENV=e2e FakePaymentGateway (ADR-023 decision #6); CHIP's
// success_callback is simulated by POSTing its flattened payload to
// /api/webhooks/chip after intercepting the subscribe response, exactly
// as storefront-checkout.spec.ts does for an order.
test("verified member picks a tier -> pays -> membership active", async ({ page, request }) => {
  test.slow();

  const verify = await request.post(`${BACKEND_URL}/api/membership/otp/verify`, {
    headers: { "Content-Type": "application/json" },
    data: { email: E2E_MEMBER_EMAIL, code: E2E_MEMBER_OTP },
  });
  expect(verify.ok()).toBeTruthy();
  const { token } = await verify.json();
  expect(token).toBeTruthy();

  await page.addInitScript(
    ([key, value]) => window.localStorage.setItem(key, value),
    [MEMBERSHIP_TOKEN_KEY, token] as const,
  );

  // 1. Land on the dashboard — the subscribe surface is visible.
  await page.goto(`${STOREFRONT_URL}/membership`);
  await expect(page.getByRole("heading", { name: "Choose a Membership" })).toBeVisible({ timeout: 15_000 });

  // 2. Pick the first tier; fpx is the only active channel so it
  //    auto-selects, and continue to payment.
  await page
    .locator("section", { hasText: "Choose a Membership" })
    .getByRole("button", { name: "Subscribe" })
    .first()
    .click();

  let subscribeJson: { subscription_number: string; total_charged_sen: number } | undefined;
  await page.route("**/api/membership/subscribe", async (route) => {
    const response = await route.fetch();
    subscribeJson = await response.json();
    await route.fulfill({ response });
  });

  await page.getByRole("button", { name: /Continue to Payment/ }).click();
  await expect.poll(() => subscribeJson, { timeout: 15_000 }).toBeTruthy();
  if (!subscribeJson) throw new Error("unreachable — asserted truthy above");
  expect(subscribeJson.subscription_number).toMatch(/^MS-/);

  // 3. Simulate CHIP's success_callback for that purchase.
  const webhook = await request.post(`${BACKEND_URL}/api/webhooks/chip`, {
    headers: { "Content-Type": "application/json" },
    data: {
      event_type: "purchase.paid",
      reference: subscribeJson.subscription_number,
      id: `e2e-purchase-${subscribeJson.subscription_number}`,
      status: "paid",
      purchase: { total: subscribeJson.total_charged_sen },
    },
  });
  expect(webhook.ok()).toBeTruthy();

  // 4. Back on /membership?checkout=success the page polls /me until the
  //    membership lands, then shows the active status card.
  await page.goto(`${STOREFRONT_URL}/membership?checkout=success`);
  await expect(page.getByText("Your current membership level.")).toBeVisible({ timeout: 45_000 });
});
