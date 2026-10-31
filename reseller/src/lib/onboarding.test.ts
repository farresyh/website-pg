import { test } from "node:test";
import assert from "node:assert/strict";
import { banners, channelsSetUp, progress, requiredDone, stepCopy, storeStatus, type Onboarding, type OnboardingStep } from "./onboarding.ts";

const s = (key: string, state: OnboardingStep["state"], required = false, detail: string | null = null): OnboardingStep => ({ key, state, required, detail });
const o = (steps: OnboardingStep[], ready = false): Onboarding => ({ ready, steps });

test("optional steps are not counted in the progress", () => {
  assert.deepEqual(progress([s("password", "done"), s("bank", "todo"), s("markup", "optional")]), { done: 1, total: 2 });
});

test("the checklist is complete when every required step is done, whatever else is open", () => {
  assert.equal(requiredDone([s("domain", "done", true), s("bank", "todo")]), true);
  assert.equal(requiredDone([s("domain", "done", true), s("dns", "todo", true)]), false);
  assert.equal(requiredDone([s("payments_approval", "waiting_on_us", true)]), false);
});

test("an affiliate with no bank details gets the payout banner, an owned brand does not", () => {
  const withBank = banners("affiliate", o([s("bank", "todo")]));
  assert.deepEqual(withBank.map((b) => b.key), ["bank"]);
  assert.equal(withBank[0].href, "/profile");

  assert.deepEqual(banners("affiliate", o([s("domain", "done", true)])), []);
  assert.deepEqual(banners("affiliate", o([s("bank", "done")])), []);
});

test("the payments banner shows only once DNS is done and we are the ones waiting", () => {
  const waiting = o([s("dns", "done", true), s("payments_approval", "waiting_on_us", true)]);
  assert.deepEqual(banners("affiliate", waiting).map((b) => b.key), ["payments"]);

  assert.deepEqual(banners("affiliate", o([s("dns", "todo", true), s("payments_approval", "todo", true)])), []);
  assert.deepEqual(banners("affiliate", o([s("dns", "done", true), s("payments_approval", "done", true)])), []);
});

test("a reseller with no tier gets a banner, and never the affiliate ones", () => {
  assert.deepEqual(banners("reseller", o([s("tier", "todo", true)])).map((b) => b.key), ["tier"]);
  assert.deepEqual(banners("reseller", o([s("tier", "done", true)])), []);
  assert.deepEqual(banners("reseller", o([s("bank", "todo")])), []);
});

test("a store is ready only when the backend says so, online when the domain is active", () => {
  assert.equal(storeStatus(o([], true), true), "ready");
  assert.equal(storeStatus(o([], false), true), "online");
  assert.equal(storeStatus(null, true), "online");
  assert.equal(storeStatus(o([], false), false), null);
});

test("the order channels come from the order_channel step", () => {
  assert.deepEqual(channelsSetUp(o([s("order_channel", "done", true, "api_key,whatsapp_group")])), { apiKey: true, whatsAppGroup: true });
  assert.deepEqual(channelsSetUp(o([s("order_channel", "done", true, "whatsapp_group")])), { apiKey: false, whatsAppGroup: true });
  assert.deepEqual(channelsSetUp(null), { apiKey: false, whatsAppGroup: false });
});

test("every step the backend can send has words for both account types", () => {
  const affiliate = ["password", "bank", "domain", "dns", "payments_approval", "branding", "markup", "first_order"];
  const reseller = ["password", "tier", "add_balance", "order_channel", "first_order", "webhook"];
  for (const key of affiliate) assert.notEqual(stepCopy("affiliate", key).text, "", `affiliate ${key}`);
  for (const key of reseller) assert.notEqual(stepCopy("reseller", key).text, "", `reseller ${key}`);
});

test("the platform's cost is never mentioned to a partner", () => {
  for (const key of ["bank", "domain", "dns", "payments_approval", "branding", "markup"]) {
    assert.doesNotMatch(stepCopy("affiliate", key).text, /margin|platform cost|markup_percent/i);
  }
});
