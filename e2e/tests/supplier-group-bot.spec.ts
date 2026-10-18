import { createHmac } from "node:crypto";
import { test, expect, type APIRequestContext } from "@playwright/test";
import { ADMIN_URL, BACKEND_URL, STOREFRONT_URL } from "./constants";
import { loginAdminApi, loginAsAdmin } from "./helpers";

// Golden path — ADR-023 Trigger A (a new order-resolution mechanism):
// ADR-125 PR 2's supplier WhatsApp bot. The supplier's group sends its first
// message (captured as pending), the admin links it in Partners → Suppliers,
// a guest checks out a login order, the order is posted to the group (queued
// on the bot session; the e2e worker never sends it), and the supplier's
// `.done` in the group delivers it at the agreed cost. Uses E2ESeeder's
// "E2E Bot Supplier" and "E2E Bot Login"; the OpenWA secret is boot-backend.sh's.
const GROUP = "e2e-supplier-group@g.us";

async function groupSays(request: APIRequestContext, body: string) {
  const payload = JSON.stringify({
    event: "message.received",
    sessionId: "e2e-bot",
    data: { kind: "group", chatId: GROUP, body, id: `m-${Date.now()}-${Math.random()}`, author: "60123456789@c.us", fromMe: false },
  });
  const signature = "sha256=" + createHmac("sha256", "e2e-openwa-secret").update(payload).digest("hex");
  const response = await request.post(`${BACKEND_URL}/api/webhooks/openwa`, {
    headers: { "Content-Type": "application/json", "X-OpenWA-Signature": signature },
    data: payload,
  });
  expect(response.ok()).toBeTruthy();
}

test("supplier group bot: link group -> login order posted -> .done delivers", async ({ page, request }) => {
  test.slow();

  // 1. The group's first message lands in the pending list; the admin links it.
  await groupSays(request, "hello bot");
  await loginAsAdmin(page);
  await page.goto(`${ADMIN_URL}/admin/suppliers`);
  const supplierRow = page.getByRole("row", { name: /E2E Bot Supplier/ });
  await expect(supplierRow.getByText("No group")).toBeVisible();
  await supplierRow.getByRole("button", { name: "WhatsApp Group" }).click();
  await page.getByRole("button", { name: "Link to this supplier" }).click();
  await expect(page.getByRole("dialog").getByText(GROUP)).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(supplierRow.getByText(GROUP)).toBeVisible();

  // 2. Storefront: a login order on that supplier's game.
  await page.goto(`${STOREFRONT_URL}/order/e2e-bot-login`);
  await page.locator("#login_email").fill("bot-buyer@example.com");
  await page.locator("#login_password").fill("bot secret");
  await page.getByRole("checkbox", { name: /I allow this store to use my game login/ }).check();
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await page.getByRole("button", { name: "400 Robux" }).click();
  await page.getByRole("button", { name: "Online Banking (FPX)" }).click();
  await page.getByRole("button", { name: /Review & Pay/ }).click();
  await page.locator("#reviewEmail").pressSequentially("e2e-bot@example.com");
  await page.locator("#reviewName").fill("E2E Bot Tester");
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

  // 3. Paid (FakePaymentGateway accepts the webhook unsigned in e2e) → Pending → posted.
  const adminHeaders = await loginAdminApi(request);
  const search = await request.get(`${BACKEND_URL}/api/orders?search=${encodeURIComponent(orderNumber)}`, { headers: adminHeaders });
  const orderId: number = (await search.json()).data[0].id;
  const orderDetail = async () => (await request.get(`${BACKEND_URL}/api/orders/${orderId}`, { headers: adminHeaders })).json();
  const placed = await orderDetail();
  const webhook = await request.post(`${BACKEND_URL}/api/webhooks/chip`, {
    headers: { "Content-Type": "application/json" },
    data: { event_type: "purchase.paid", reference: orderNumber, id: placed.payment_ref, status: "paid", purchase: { total: placed.final_amount } },
  });
  expect(webhook.ok()).toBeTruthy();
  await expect.poll(async () => (await orderDetail()).delivery_status, { timeout: 60_000, intervals: [1_000, 2_000, 3_000] }).toBe("pending");

  const pending = await orderDetail();
  const post = pending.customer_notifications.find((n: { event: string }) => n.event === "supplier_order_post");
  expect(post).toMatchObject({ session: "bot", chat_id: GROUP, status: "queued" });

  await page.goto(`${ADMIN_URL}/admin/orders?order=${orderId}`);
  await expect(page.getByText("Supplier group").first()).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText("Order post").first()).toBeVisible();

  // 4. The supplier replies in its group: delivered at the agreed cost, sender recorded.
  await groupSays(request, `.done ${orderNumber.toLowerCase()}`);
  const delivered = await orderDetail();
  expect(delivered.delivery_status).toBe("delivered");
  expect(delivered.real_cost_price_sen).toBe(2000);
  expect(
    delivered.resend_attempts.some((a: { attempt_type: string; triggered_by: string }) => a.attempt_type === "manual_confirm" && a.triggered_by === "wa:60123456789"),
  ).toBe(true);
});
