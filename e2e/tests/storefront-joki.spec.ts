import { test, expect } from "@playwright/test";
import { ADMIN_URL, BACKEND_URL, STOREFRONT_URL } from "./constants";
import { loginAdminApi, loginAsAdmin } from "./helpers";

// Golden path — ADR-023 Trigger A (a new order path): ADR-126 Joki end to
// end. The admin switches Joki on (which sets up the joki game) and lets
// the primary brand sell it; a guest prices a climb on /joki, gives their
// login and pays; after the (simulated) CHIP webhook the order waits for
// a penjoki on the assignment pool, the status page shows the climb, and
// the admin's Joki board lists it as waiting. Draft rates: Epic V 0★ →
// Epic IV 1★ is 6 Epic stars at RM2 = RM12.
test("joki: switch on -> price a climb on /joki -> pay -> waits for a penjoki", async ({ page, request }) => {
  test.slow();

  // 1. Admin: Joki on, and the primary brand sells it.
  await loginAsAdmin(page);
  await page.goto(`${ADMIN_URL}/admin/joki`);
  await page.getByRole("tab", { name: "Settings" }).click();
  await page.getByRole("button", { name: "Switch on" }).click();
  await expect(page.getByText("Joki switched on.")).toBeVisible();

  await page.goto(`${ADMIN_URL}/admin/affiliates`);
  await page.getByRole("row", { name: /PekanGame/ }).getByRole("button", { name: "Edit" }).click();
  await page.getByRole("checkbox", { name: /Sell Joki on this storefront/ }).check();
  await page.getByRole("button", { name: "Save Changes" }).click();
  await expect(page.getByRole("button", { name: "Save Changes" })).toBeHidden();

  // Both switches really are on before the storefront is asked.
  const setupHeaders = await loginAdminApi(request);
  const { affiliates } = await (await request.get(`${BACKEND_URL}/api/affiliates`, { headers: setupHeaders })).json();
  expect(affiliates.find((a: { business_name: string }) => a.business_name === "PekanGame")?.joki_enabled).toBe(true);
  const jokiSettings = await (await request.get(`${BACKEND_URL}/api/joki-admin/settings`, { headers: setupHeaders })).json();
  expect(jokiSettings.settings.is_enabled).toBe(true);

  // 2. Storefront: /joki appears once the 30s catalog cache turns over
  // (no revalidate webhook in e2e).
  await expect
    .poll(
      async () => {
        await page.goto(`${STOREFRONT_URL}/joki`);
        return page.getByRole("heading", { name: "Joki Rank MLBB" }).isVisible();
      },
      { timeout: 90_000, intervals: [3_000, 5_000, 10_000] },
    )
    .toBe(true);
  await expect(page.getByRole("link", { name: "Joki Rank" }).first()).toBeAttached();

  // The calculator opens on Epic V → Epic IV (5 Epic stars at RM2), priced at once.
  await expect(page.getByTestId("joki-from-label")).toHaveText("Epic V");
  await expect(page.getByTestId("joki-to-label")).toHaveText("Epic IV");
  await expect(page.getByTestId("joki-climb-price")).toHaveText("RM10.00");
  // One more target star: 6 stars.
  await page.getByRole("button", { name: "Target rank: more stars" }).click();
  await expect(page.getByTestId("joki-climb-price")).toHaveText("RM12.00");

  await page.locator("#login_login_type").selectOption("Moonton");
  await page.locator("#login_user_id").fill("123456789");
  await page.locator("#login_login").fill("e2e_player");
  await page.locator("#login_password").fill("e2e-secret");
  await page.getByRole("checkbox", { name: /I allow this store to use my game login/ }).check();
  await page.locator("#joki-role").selectOption("Jungler");
  await page.locator("#joki-hero").fill("Ling");

  await page.getByRole("button", { name: "Online Banking (FPX)" }).click();
  await page.getByRole("button", { name: /Review & Pay/ }).first().click();

  await page.locator("#reviewEmail").fill("e2e-joki@example.com");
  await page.locator("#reviewName").fill("E2E Joki Buyer");
  await page.locator("#reviewPhone").fill("0123456789");
  await page.getByRole("checkbox", { name: /I agree to the Terms/i }).check();

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

  // 3. Pay (CHIP webhook, faked in e2e), then the order waits on the pool.
  const adminHeaders = await loginAdminApi(request);
  const { data } = await (await request.get(`${BACKEND_URL}/api/orders?type=joki&search=${encodeURIComponent(orderNumber)}`, { headers: adminHeaders })).json();
  const orderId: number = data[0].id;
  const order = await (await request.get(`${BACKEND_URL}/api/orders/${orderId}`, { headers: adminHeaders })).json();
  expect(order.selling_price).toBe(1200);
  expect(order.pricing_basis).toBe("fixed");

  const webhook = await request.post(`${BACKEND_URL}/api/webhooks/chip`, {
    headers: { "Content-Type": "application/json" },
    data: { event_type: "purchase.paid", reference: orderNumber, id: order.payment_ref, status: "paid", purchase: { total: order.final_amount } },
  });
  expect(webhook.ok()).toBeTruthy();

  await expect
    .poll(
      async () => (await (await request.get(`${BACKEND_URL}/api/joki-admin/summary`, { headers: adminHeaders })).json()).waiting,
      { timeout: 60_000, intervals: [1_000, 2_000, 3_000] },
    )
    .toBe(1);

  // 4. The customer sees the climb; the admin sees it waiting.
  await page.goto(`${STOREFRONT_URL}/order/status/${encodeURIComponent(orderNumber)}`);
  await expect(page.getByText("Epic V 0★ → Epic IV 1★")).toBeVisible({ timeout: 30_000 });

  await page.goto(`${ADMIN_URL}/admin/joki`);
  await expect(page.getByRole("row", { name: new RegExp(orderNumber) })).toContainText("Unassigned");
});
