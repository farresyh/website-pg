import { test, expect } from "@playwright/test";
import {
  BACKEND_URL,
  STOREFRONT_URL,
  E2E_ADMIN_EMAIL,
  E2E_ADMIN_PASSWORD,
  E2E_GAME_SLUG,
} from "./constants";

// Golden path — ADR-023. Covers the storefront's only revenue path:
// guest checkout -> payment -> order status. Needs no payment
// credential: since ADR-022's 2026-09-01 addendum the payment layer is
// faked at the backend's container-binding level for APP_ENV=e2e
// (AppServiceProvider::register() -> FakePaymentGateway), the same way
// Gamevion is (CHIP verifies webhooks with an RSA signature this spec
// can't forge). The webhook callback is simulated by POSTing CHIP's
// real flattened payload shape directly to /api/webhooks/chip after
// capturing the payment_ref the fake gateway's createPayment returned.
test("guest checkout -> payment -> order status", async ({ page, request }) => {
  // ~12-step flow ending in an async delivery + a cold-compiled status
  // route — legitimately over the 60s default on a cold CI runner.
  test.slow();

  await page.goto(`${STOREFRONT_URL}/order/${E2E_GAME_SLUG}`);

  await page.locator("#playerId").fill("900000001");
  // exact:true — the sidebar's disabled "Complete steps to continue"
  // button otherwise substring-matches "Continue" too.
  await page.getByRole("button", { name: "Continue", exact: true }).click();

  await page.getByRole("button", { name: "E2E Test Package" }).click();
  // The one CHIP FPX button (E2ESeeder activates channel_code 'fpx');
  // bank selection happens on CHIP's own hosted page, not here.
  await page.getByRole("button", { name: "Online Banking (FPX)" }).click();

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

  // Look up the payment_ref the gateway assigned — never exposed to
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

  // Simulate CHIP's webhook callback (ADR-023 decision #6) — CHIP's
  // real flattened Purchase shape per ChipGateway::parseWebhookEvent();
  // FakePaymentGateway accepts it unsigned in the e2e env.
  const webhook = await request.post(`${BACKEND_URL}/api/webhooks/chip`, {
    headers: { "Content-Type": "application/json" },
    data: {
      event_type: "purchase.paid",
      reference: orderNumber,
      id: order.payment_ref,
      status: "paid",
      purchase: { total: order.final_amount },
    },
  });
  expect(webhook.ok()).toBeTruthy();

  // Delivery is async (queued FulfillOrderJob -> FakeSupplierGateway).
  // Wait for the terminal state on the admin API — fast JSON, no
  // Next.js route compile inside the loop — THEN assert the
  // customer-facing status page reflects it. ADR-023 wants a flake
  // fixed, not re-run: the bare status-page poll flaked repeatedly
  // (PRs #68, #69) because a cold-compile of the status route raced
  // the poll cadence, and a genuinely stuck job surfaced only as a
  // vague page timeout. Decoupling the two makes "job never ran" fail
  // here as "delivery_status never reached delivered".
  await expect
    .poll(
      async () => {
        const res = await request.get(`${BACKEND_URL}/api/orders/${orderId}`, {
          headers: { Authorization: `Bearer ${token}` },
        });
        return (await res.json()).delivery_status;
      },
      { timeout: 60_000, intervals: [1_000, 2_000, 3_000] },
    )
    .toBe("delivered");

  await page.goto(`${STOREFRONT_URL}/order/status/${encodeURIComponent(orderNumber)}`);
  await expect(page.getByText("Delivered")).toBeVisible({ timeout: 15_000 });
});
