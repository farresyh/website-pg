import { test, expect } from "@playwright/test";
import { BACKEND_URL, E2E_MEMBER_EMAIL, E2E_MEMBER_OTP } from "./constants";

// Golden path — ADR-068. Covers the self-serve membership subscription
// revenue path end to end: verify -> subscribe -> pay -> membership
// active. Pure API (the /membership subscribe UI is a separate PR); the
// payment layer is the same APP_ENV=e2e FakePaymentGateway the checkout
// golden path uses (ADR-023 decision #6), and the CHIP webhook is
// simulated by POSTing CHIP's flattened payload shape to
// /api/webhooks/chip after capturing the subscription_number — exactly
// as storefront-checkout.spec.ts does for an order.
test("member verifies -> subscribes -> pays -> membership active", async ({ request }) => {
  // 1. Obtain a real 30-day session token from the pre-verifiable OTP
  //    fixture E2ESeeder plants (MAIL_MAILER=log in e2e, so there is no
  //    inbox to read a live code from).
  const verify = await request.post(`${BACKEND_URL}/api/membership/otp/verify`, {
    headers: { "Content-Type": "application/json" },
    data: { email: E2E_MEMBER_EMAIL, code: E2E_MEMBER_OTP },
  });
  expect(verify.ok()).toBeTruthy();
  const { token } = await verify.json();
  expect(token).toBeTruthy();
  const auth = { Authorization: `Bearer ${token}` };

  // 2. Pick a tier from the authenticated options endpoint.
  const optionsRes = await request.get(`${BACKEND_URL}/api/membership/subscribe-options`, { headers: auth });
  expect(optionsRes.ok()).toBeTruthy();
  const options = await optionsRes.json();
  expect(options.current_plan_id).toBeNull();
  const planId = options.plans[0].id;

  // 3. Start the subscription checkout.
  const subscribeRes = await request.post(`${BACKEND_URL}/api/membership/subscribe`, {
    headers: { ...auth, "Content-Type": "application/json" },
    data: { membership_plan_id: planId, payment_method: "fpx", idempotency_key: `e2e-${Date.now()}` },
  });
  expect(subscribeRes.status()).toBe(201);
  const { subscription_number, checkout_url, total_charged_sen } = await subscribeRes.json();
  expect(subscription_number).toMatch(/^MS-/);
  expect(checkout_url).toBeTruthy();

  // 4. Simulate CHIP's success_callback for that purchase.
  const webhook = await request.post(`${BACKEND_URL}/api/webhooks/chip`, {
    headers: { "Content-Type": "application/json" },
    data: {
      event_type: "purchase.paid",
      reference: subscription_number,
      id: `e2e-purchase-${subscription_number}`,
      status: "paid",
      purchase: { total: total_charged_sen },
    },
  });
  expect(webhook.ok()).toBeTruthy();

  // 5. The membership is now active for this session.
  const meRes = await request.get(`${BACKEND_URL}/api/membership/me`, { headers: auth });
  expect(meRes.ok()).toBeTruthy();
  const me = await meRes.json();
  expect(me.membership).not.toBeNull();
  expect(me.membership.status).toBe("active");
});
