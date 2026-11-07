import { test, expect } from "@playwright/test";
import { STOREFRONT_URL, E2E_MEMBER_OTP } from "./constants";
import { comparePlans, estimatePlan } from "../../storefront/src/lib/membership-calculator";

const LANDING_EMAIL = "e2e-landing@example.com";

// Production's plans on 2026-11-07, so these figures can be read against the page's real numbers.
const tier1 = { id: 1, name: "Tier 1", feeRm: 6.9, lowestChargeRm: 6.97, quotaRm: 335, realSavingsPercent: 4.5 };
const tier2 = { id: 2, name: "Tier 2", feeRm: 19.9, lowestChargeRm: 20.1, quotaRm: 605, realSavingsPercent: 6.4 };

test("the calculator keeps what a plan really leaves after its fee and its limit", () => {
  const at300 = comparePlans([tier1, tier2], 300);
  expect(at300.best?.plan.name).toBe("Tier 1");
  expect(at300.best?.netRm).toBeCloseTo(6.53, 2);
  expect(estimatePlan(tier2, 300).netRm).toBeCloseTo(-0.9, 2);

  // Nothing pays off yet: the plan that gets there soonest leads, and none is called best.
  const at100 = comparePlans([tier1, tier2], 100);
  expect(at100.best).toBeNull();
  expect(at100.featured.plan.name).toBe("Tier 1");
  expect(at100.featured.savingRm).toBeCloseTo(4.5, 2);

  expect(estimatePlan(tier1, 300).breakEvenRm).toBe(155);
  expect(estimatePlan(tier2, 300).breakEvenRm).toBe(314);

  // Past Tier 1's limit its saving stops, so Tier 2 overtakes and is listed first.
  const at600 = comparePlans([tier1, tier2], 600);
  expect(estimatePlan(tier1, 600).savingRm).toBeCloseTo(15.79, 2);
  expect(at600.best?.plan.name).toBe("Tier 2");
  expect(at600.estimates[0].plan.name).toBe("Tier 2");

  // A fee the cap can never earn back has no break-even to promise.
  expect(estimatePlan({ ...tier1, lowestChargeRm: 50 }, 300).breakEvenRm).toBeNull();
  expect(estimatePlan({ ...tier1, realSavingsPercent: null }, 300).breakEvenRm).toBeNull();
});

// ADR-055 2026-11-07 addendum. A phone-sized visitor sees the offer before any
// email field, chooses a plan, survives a reload on the way back from the mail
// app, and lands on that plan's payment step.
test("a signed-out phone visitor chooses a plan and reaches its payment step", async ({ page }) => {
  test.slow();
  await page.setViewportSize({ width: 390, height: 844 });

  // A link straight to the calculator keeps its place.
  await page.goto(`${STOREFRONT_URL}/membership#calculator`);
  await expect(page.getByRole("heading", { name: "Pay less on every top-up." })).toBeAttached({ timeout: 15_000 });
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0);

  await page.goto(`${STOREFRONT_URL}/membership`);
  await expect(page.getByRole("heading", { name: "Pay less on every top-up." })).toBeVisible();
  await expect(page.getByRole("textbox", { name: "Email address" })).toHaveCount(0);
  await expect(page.getByText("E2E 284 Diamonds").first()).toBeVisible();

  // Below break-even: the saving, the fee and the break-even are all on show, no loss figure, and a plan can still be tried.
  await page.getByRole("button", { name: "RM100", exact: true }).click();
  await expect(page.getByText(/pays for itself at about RM\d+ a month/)).toBeVisible();
  await expect(page.getByText("Membership costs").first()).toBeVisible();
  await expect(page.getByRole("button", { name: /^Try .+ for 30 days$/ }).first()).toBeVisible();
  await expect(page.locator("#calculator").getByText(/-RM/)).toHaveCount(0);

  await page.getByRole("button", { name: "RM600", exact: true }).click();

  // The sticky button waits until the calculator is gone and clears the page end.
  const sticky = page.locator("div.fixed").getByRole("button", { name: /^Subscribe to/ });
  await expect(sticky).toHaveCount(0);
  await page.getByRole("heading", { name: "How it works" }).evaluate((e) => window.scrollTo(0, e.getBoundingClientRect().top + scrollY - 120));
  await expect(sticky).toBeVisible();
  await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
  await expect(sticky).toHaveCount(0);
  await page.evaluate(() => window.scrollTo(0, 0));

  const subscribe = page.getByRole("button", { name: /^Subscribe to/ }).first();
  await expect(subscribe).toBeVisible();
  const planName = ((await subscribe.textContent()) ?? "").replace("Subscribe to", "").trim();
  await subscribe.click();

  await expect(page.getByRole("heading", { name: `Subscribe to ${planName}` })).toBeVisible();
  // The fixture's code must survive: a real send would replace it.
  await page.route("**/api/membership/otp/send", (route) => route.fulfill({ json: { message: "sent" } }));
  await page.getByRole("textbox", { name: "Email address" }).fill(LANDING_EMAIL);
  await page.getByRole("button", { name: "Send Code" }).click();
  await expect(page.getByRole("heading", { name: "Enter Your Code" })).toBeVisible();

  await page.reload();
  await expect(page.getByRole("heading", { name: "Enter Your Code" })).toBeVisible();
  await expect(page.getByText(LANDING_EMAIL)).toBeVisible();

  const digits = page.locator('input[inputmode="numeric"]');
  for (const [index, digit] of [...E2E_MEMBER_OTP].entries()) await digits.nth(index).fill(digit);
  await page.getByRole("button", { name: "Verify" }).click();

  await expect(page.getByText(`Subscribing to ${planName}`)).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText("Best value")).toHaveCount(0);

  // Nothing of the sign-in is left behind for a later visit.
  expect(await page.evaluate(() => sessionStorage.getItem("krs_membership_signin"))).toBeNull();
});
