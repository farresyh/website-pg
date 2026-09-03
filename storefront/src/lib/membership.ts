import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";
import { catalogCache, safeRead } from "@/lib/cache";

/**
 * ADR-027's 2026-08-29 addendum, decisions 23/24/25/27 — the
 * /membership page's data layer: send/verify OTP (email, not
 * WhatsApp), then the dashboard (status/quota/order history). ADR-044:
 * schemas are the source of truth for wire shapes below.
 */

const VerifyOtpWireSchema = z.object({ token: z.string() });

export function sendOtp(email: string) {
  return apiFetch<{ message: string }>("/api/membership/otp/send", { method: "POST", body: { email } });
}

/**
 * ADR-055 decision 3: the upsell card's tier data — `name`/`fee_sen`/
 * `discount_percent` per tier, no `quota_sen`/`id`/timestamps, `[]`
 * when the membership kill switch is off.
 */
const MembershipPlanWireSchema = z.object({
  name: z.string(),
  fee_sen: z.number(),
  discount_percent: z.number(),
});

export interface MembershipPlan {
  name: string;
  feeSen: number;
  discountPercent: number;
}

/**
 * The membership tier list, doubling as the storefront-wide "is
 * membership enabled" flag (`[]` when the kill switch is off — ADR-055).
 * Storefront-wide config, not per-user, so it joins the `catalog`
 * Data-Cache tag (ADR-071 PR1). A failed read reads as "off", never an
 * error. PR2 lifts the server read into `SiteConfigProvider` so
 * `SiteHeader` / `BottomNav` stop each re-fetching it per navigation.
 */
export async function listPlans(): Promise<MembershipPlan[]> {
  const path = "/api/membership/plans";
  return safeRead(
    "listPlans",
    async () => {
      const raw = await apiFetch<unknown>(path, { next: catalogCache });
      const wire = parseResponse(z.array(MembershipPlanWireSchema), raw, "MembershipPlanWire[]", path);
      return wire.map((plan) => ({
        name: plan.name,
        feeSen: plan.fee_sen,
        discountPercent: plan.discount_percent,
      }));
    },
    [],
  );
}

export async function verifyOtp(email: string, code: string): Promise<string> {
  const raw = await apiFetch<unknown>("/api/membership/otp/verify", { method: "POST", body: { email, code } });
  const wire = parseResponse(VerifyOtpWireSchema, raw, "VerifyOtpWire", "/api/membership/otp/verify");
  return wire.token;
}

const MembershipWireSchema = z.object({
  tier_name: z.string(),
  status: z.string(),
  expires_at: z.string(),
  cycle_started_at: z.string(),
  quota_remaining_sen: z.number(),
});

const OrderHistoryRowWireSchema = z.object({
  order_number: z.string(),
  game: z.object({ name: z.string(), slug: z.string() }).nullable(),
  package_name: z.string().nullable(),
  final_amount: z.number(),
  payment_status: z.string(),
  delivery_status: z.string(),
  created_at: z.string().nullable(),
});

const MeWireSchema = z.object({
  email: z.string(),
  membership: MembershipWireSchema.nullable(),
  order_history: z.array(OrderHistoryRowWireSchema),
});

export interface MembershipDashboard {
  tierName: string;
  status: string;
  expiresAt: string;
  cycleStartedAt: string;
  quotaRemainingRm: number;
}

export interface MembershipOrderHistoryRow {
  orderNumber: string;
  gameName: string | null;
  packageName: string | null;
  finalAmountRm: number;
  paymentStatus: string;
  deliveryStatus: string;
  createdAt: string | null;
}

export interface MembershipMe {
  /** ADR-068 decision 16 — the member's OTP-verified email; the checkout contact email is bound to this. */
  email: string;
  membership: MembershipDashboard | null;
  orderHistory: MembershipOrderHistoryRow[];
}

export async function getMe(token: string): Promise<MembershipMe> {
  const raw = await apiFetch<unknown>("/api/membership/me", { token });
  const wire = parseResponse(MeWireSchema, raw, "MeWire", "/api/membership/me");

  return {
    email: wire.email,
    membership:
      wire.membership !== null
        ? {
            tierName: wire.membership.tier_name,
            status: wire.membership.status,
            expiresAt: wire.membership.expires_at,
            cycleStartedAt: wire.membership.cycle_started_at,
            quotaRemainingRm: wire.membership.quota_remaining_sen / 100,
          }
        : null,
    orderHistory: wire.order_history.map((row) => ({
      orderNumber: row.order_number,
      gameName: row.game?.name ?? null,
      packageName: row.package_name,
      finalAmountRm: row.final_amount / 100,
      paymentStatus: row.payment_status,
      deliveryStatus: row.delivery_status,
      createdAt: row.created_at,
    })),
  };
}

/**
 * ADR-068 decision 6 — the authenticated subscribe view's data source:
 * full plan rows (unlike the anonymous, narrow `listPlans()`), the
 * caller's current plan, and a per-plan relation the UI uses to label
 * and gate each option (`downgrade` is shown but disabled — S5).
 */
const SubscribePlanWireSchema = z.object({
  id: z.number(),
  name: z.string(),
  fee_sen: z.number(),
  quota_sen: z.number(),
  discount_percent: z.number(),
  relation: z.enum(["renew", "upgrade", "downgrade", "subscribe"]),
});

const SubscribeOptionsWireSchema = z.object({
  current_plan_id: z.number().nullable(),
  plans: z.array(SubscribePlanWireSchema),
});

export type SubscribeRelation = z.infer<typeof SubscribePlanWireSchema>["relation"];

export interface SubscribePlan {
  id: number;
  name: string;
  feeRm: number;
  quotaRm: number;
  discountPercent: number;
  relation: SubscribeRelation;
}

export interface SubscribeOptions {
  currentPlanId: number | null;
  plans: SubscribePlan[];
}

export async function getSubscribeOptions(token: string): Promise<SubscribeOptions> {
  const raw = await apiFetch<unknown>("/api/membership/subscribe-options", { token });
  const wire = parseResponse(SubscribeOptionsWireSchema, raw, "SubscribeOptionsWire", "/api/membership/subscribe-options");
  return {
    currentPlanId: wire.current_plan_id,
    plans: wire.plans.map((plan) => ({
      id: plan.id,
      name: plan.name,
      feeRm: plan.fee_sen / 100,
      quotaRm: plan.quota_sen / 100,
      discountPercent: plan.discount_percent,
      relation: plan.relation,
    })),
  };
}

const SubscribeWireSchema = z.object({
  subscription_number: z.string(),
  checkout_url: z.string().nullable(),
  fee_sen: z.number(),
  total_charged_sen: z.number(),
});

export interface SubscribeResult {
  subscriptionNumber: string;
  checkoutUrl: string | null;
  feeRm: number;
  totalChargedRm: number;
}

/**
 * ADR-068 decision 5 — start a self-serve subscription payment. `planId`
 * is the only monetary input (the fee is read server-side, ORD-9);
 * `idempotencyKey` is client-generated and reused across a retry of the
 * same attempt, mirroring checkout. Returns the CHIP checkout URL to
 * redirect to.
 */
export async function subscribe(
  token: string,
  planId: number,
  paymentMethod: string,
  idempotencyKey: string,
): Promise<SubscribeResult> {
  const path = "/api/membership/subscribe";
  const raw = await apiFetch<unknown>(path, {
    method: "POST",
    token,
    body: { membership_plan_id: planId, payment_method: paymentMethod, idempotency_key: idempotencyKey },
  });
  const wire = parseResponse(SubscribeWireSchema, raw, "SubscribeWire", path);
  return {
    subscriptionNumber: wire.subscription_number,
    checkoutUrl: wire.checkout_url,
    feeRm: wire.fee_sen / 100,
    totalChargedRm: wire.total_charged_sen / 100,
  };
}
