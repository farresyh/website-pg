import { test, expect } from "@playwright/test";
import { ADMIN_URL, BACKEND_URL, STOREFRONT_URL } from "./constants";
import { loginAdminApi, loginAsAdmin } from "./helpers";

// Golden path — ADR-023 Trigger A (a new order-resolution mechanism):
// ADR-125's Login Top-up end to end. An admin creates a login game and a
// manual package; a guest checks out with their game login; after payment
// the order waits in the Manual Queue (the real ManualSupplierAdapter
// answers Pending, no network); the admin marks it Delivered at the agreed
// cost; the customer's status page shows the login masked and asks for a
// password change. Uses E2ESeeder's "E2E Manual Supplier".
test("login top-up: create game -> checkout with login -> Manual Queue -> delivered", async ({ page, request }) => {
  test.slow();

  // 1. Admin: login game, manual package, activate.
  await loginAsAdmin(page);
  await page.goto(`${ADMIN_URL}/admin/games`);
  await page.waitForLoadState("networkidle");
  await page.getByRole("button", { name: "New Login Game" }).click();
  await page.locator("#login_game_name").fill("E2E Roblox");
  await page.getByRole("button", { name: "Create Login Game" }).click();
  await expect(page.getByRole("heading", { name: "E2E Roblox" })).toBeVisible();

  await page.getByRole("button", { name: "Add Package…" }).click();
  await page.locator("#manual_name").fill("800 Robux");
  await page.locator("#manual_cost").fill("40.00");
  await expect(page.getByText("Selling price: RM 46.00")).toBeVisible();
  await page.getByRole("button", { name: "Add Package", exact: true }).click();
  await expect(page.getByText("800 Robux")).toBeVisible();

  await page.getByRole("button", { name: "Edit Game" }).click();
  await page.getByRole("checkbox", { name: /Active \(visible to customers/ }).check();
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await expect(page.getByText(/— Active —/)).toBeVisible();

  // 2. Storefront: the login form replaces the Player ID field.
  await page.goto(`${STOREFRONT_URL}/order/e2e-roblox`);
  await expect(page.locator("#playerId")).toHaveCount(0);
  await page.locator("#login_email").fill("Player@Example.com");
  await page.locator("#login_password").fill("  secret pass  ");
  await page.getByRole("checkbox", { name: /I allow this store to use my game login/ }).check();
  await page.getByRole("button", { name: "Continue", exact: true }).click();

  await page.getByRole("button", { name: "800 Robux" }).click();
  await page.getByRole("button", { name: "Online Banking (FPX)" }).click();
  await page.getByRole("button", { name: /Review & Pay/ }).click();
  // The review shows the identifier, never the password.
  await expect(page.getByText("Player@Example.com").first()).toBeVisible();
  await expect(page.getByText("secret pass")).toHaveCount(0);

  await page.locator("#reviewEmail").pressSequentially("e2e-login@example.com");
  await page.locator("#reviewName").fill("E2E Login Tester");
  await page.locator("#reviewPhone").fill("0123456789");
  await page.getByRole("checkbox", { name: /I agree to the Terms/i }).check();

  let checkoutJson: { order_number: string } | undefined;
  await page.route("**/api/checkout", async (route) => {
    const response = await route.fetch();
    checkoutJson = await response.json();
    await route.fulfill({ response });
  });
  await page.getByRole("button", { name: /Confirm & Pay/ }).click();
  await expect.poll(() => checkoutJson, { timeout: 15_000 }).toBeTruthy();
  const orderNumber = checkoutJson!.order_number;

  // 3. Payment webhook (FakePaymentGateway accepts it unsigned in e2e).
  const adminHeaders = await loginAdminApi(request);
  const search = await request.get(`${BACKEND_URL}/api/orders?search=${encodeURIComponent(orderNumber)}`, { headers: adminHeaders });
  const orderId: number = (await search.json()).data[0].id;
  const order = await (await request.get(`${BACKEND_URL}/api/orders/${orderId}`, { headers: adminHeaders })).json();
  expect(order.player_id).toBe("player@example.com");
  expect(order.customer_login.secret_fields).toEqual([{ key: "password", label: "Password" }]);

  const webhook = await request.post(`${BACKEND_URL}/api/webhooks/chip`, {
    headers: { "Content-Type": "application/json" },
    data: { event_type: "purchase.paid", reference: orderNumber, id: order.payment_ref, status: "paid", purchase: { total: order.final_amount } },
  });
  expect(webhook.ok()).toBeTruthy();

  const deliveryStatus = async () =>
    (await (await request.get(`${BACKEND_URL}/api/orders/${orderId}`, { headers: adminHeaders })).json()).delivery_status;
  await expect.poll(deliveryStatus, { timeout: 60_000, intervals: [1_000, 2_000, 3_000] }).toBe("pending");

  // 4. Manual Queue: the admin confirms at the agreed cost.
  await page.goto(`${ADMIN_URL}/admin/manual-queue`);
  await page.waitForLoadState("networkidle");
  const row = page.locator("li", { hasText: orderNumber });
  await expect(row.getByText("player@example.com")).toBeVisible();
  await row.getByRole("button", { name: "Mark Delivered…" }).click();
  await expect(page.locator("#manual_real_cost")).toHaveValue("40.00");
  await page.getByRole("button", { name: "Mark Delivered", exact: true }).click();
  await expect(page.getByText("Nothing waiting.")).toBeVisible();

  const delivered = await (await request.get(`${BACKEND_URL}/api/orders/${orderId}`, { headers: adminHeaders })).json();
  expect(delivered.delivery_status).toBe("delivered");
  expect(delivered.real_cost_price_sen).toBe(4000);
  expect(delivered.resend_attempts.some((a: { attempt_type: string; triggered_by: string }) => a.attempt_type === "manual_confirm")).toBe(true);

  // 5. Customer status page: masked login, password-change line.
  await page.goto(`${STOREFRONT_URL}/order/status/${encodeURIComponent(orderNumber)}`);
  await expect(page.getByText("Delivered")).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText("p••••@example.com")).toBeVisible();
  await expect(page.getByText("player@example.com")).toHaveCount(0);
  await expect(page.getByText(/Please change your game password now/)).toBeVisible();
});
