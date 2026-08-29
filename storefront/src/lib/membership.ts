import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";

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
  membership: MembershipDashboard | null;
  orderHistory: MembershipOrderHistoryRow[];
}

export async function getMe(token: string): Promise<MembershipMe> {
  const raw = await apiFetch<unknown>("/api/membership/me", { token });
  const wire = parseResponse(MeWireSchema, raw, "MeWire", "/api/membership/me");

  return {
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
