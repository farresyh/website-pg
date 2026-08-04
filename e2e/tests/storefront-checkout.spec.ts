import { test, expect } from "@playwright/test";
import {
  BACKEND_URL,
  STOREFRONT_URL,
  E2E_ADMIN_EMAIL,
  E2E_ADMIN_PASSWORD,
  E2E_GAME_SLUG,
  XENDIT_WEBHOOK_TOKEN,
} from "./constants";

// Golden path — ADR-023. Covers the storefront's only revenue path:
// guest checkout -> payment -> order status. Requires a real
// XENDIT_SECRET_KEY (test-mode) in the environment booting the
// backend (see e2e/scripts/boot-backend.sh) — Xendit is hit for real
// against its own working sandbox (ADR-023 decision #6), unlike
// Gamevion, which is faked unconditionally at the backend's container-
// binding level (AppServiceProvider::register(), APP_ENV=e2e) and
// needs no credential here. The real Xendit webhook callback is never
// awaited — CI has no public URL to receive one — it's simulated by
// POSTing a validly-signed payload directly to /api/webhooks/xendit
// after capturing the real payment_ref Xendit's own API returned.
test("guest checkout -> payment -> order status", async ({ page, request }) => {
  await page.goto(`${STOREFRONT_URL}/order/${E2E_GAME_SLUG}`);

  await page.locator("#playerId").fill("900000001");
  // exact:true — the sidebar's disabled "Complete steps to continue"
  // button otherwise substring-matches "Continue" too.
  await page.getByRole("button", { name: "Continue", exact: true }).click();

  await page.getByRole("button", { name: "E2E Test Package" }).click();
  await page.getByRole("button", { name: "AmBank" }).click();

  await page.getByRole("button", { name: /Review & Pay/ }).click();

  await page.locator("#reviewEmail").fill("e2e-checkout@example.com");
  await page.locator("#reviewName").fill("E2E Checkout Tester");
  await page.locator("#reviewPhone").fill("0123456789");
  await page.getByRole("checkbox").check();

  // Routed through page.route(), not page.waitForResponse() — the app
  // calls window.location.assign(redirectUrl) as soon as its own
  // submitCheckout() promise resolves (OrderForm.tsx's
  // handleConfirmPayment), and that navigation reliably beats
  // Playwright's CDP round-trip to fetch the response body after the
  // fact ("Response body is not available for a response that was
  // navigated away from"). Intercepting the request ourselves via
  // route.fetch() reads the body independently of what the page does
  // with it next, then route.fulfill() hands the exact same response
  // back so the app's own flow is untouched.
  let checkoutJson: { order_number: string; final_amount: number } | undefined;
  await page.route("**/api/checkout", async (route) => {
    const response = await route.fetch();
    checkoutJson = await response.json();
    await route.fulfill({ response });
  });
  await page.getByRole("button", { name: /Confirm & Pay/ }).click();
  await expect.poll(() => checkoutJson, { timeout: 15_000 }).toBeTruthy();
  if (!checkoutJson) throw new Error("unreachable — asserted truthy above");

  const orderNumber = checkoutJson.order_number;
  expect(orderNumber).toBeTruthy();

  // Look up the real payment_ref Xendit assigned — never exposed to
  // the storefront's own narrow response shape (ORD-9) — via the
  // already-authenticated admin API, the same way an operator would.
  const login = await request.post(`${BACKEND_URL}/api/login`, {
    data: { email: E2E_ADMIN_EMAIL, password: E2E_ADMIN_PASSWORD },
  });
  const { token } = await login.json();

  const search = await request.get(`${BACKEND_URL}/api/orders?search=${encodeURIComponent(orderNumber)}`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  const { data } = await search.json();
  const orderId: number = data[0].id;

  const detail = await request.get(`${BACKEND_URL}/api/orders/${orderId}`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  const order = await detail.json();
  expect(order.payment_ref).toBeTruthy();

  // Simulate Xendit's webhook callback (ADR-023 decision #6) — real
  // shape per XenditGateway::parseWebhookEvent().
  const webhook = await request.post(`${BACKEND_URL}/api/webhooks/xendit`, {
    headers: { "x-callback-token": XENDIT_WEBHOOK_TOKEN, "Content-Type": "application/json" },
    data: {
      event: "payment.capture",
      data: {
        reference_id: orderNumber,
        payment_request_id: order.payment_ref,
        status: "SUCCEEDED",
        request_amount: order.final_amount / 100,
      },
    },
  });
  expect(webhook.ok()).toBeTruthy();

  await page.goto(`${STOREFRONT_URL}/order/status/${encodeURIComponent(orderNumber)}`);
  await expect(page.getByText("Delivered")).toBeVisible({ timeout: 30_000 });
});
