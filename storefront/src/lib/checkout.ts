import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";

/**
 * Real request/response contracts for two backend endpoints —
 * POST /api/games/{game}/validate-player (PlayerValidationController)
 * and POST /api/checkout (CheckoutController). `gameId`/`packageId`
 * now come from the real public catalog (lib/catalog.ts), not
 * placeholder data.
 *
 * ADR-044: schemas are the source of truth for these shapes (`z.infer`
 * below), not separately hand-written interfaces — a schema and an
 * interface describing the same wire shape would just be two copies of
 * the same fact able to drift apart, which is what this ADR exists to
 * close.
 */

const ValidatePlayerResultSchema = z.object({
  status: z.enum(["invalid", "region_unknown", "wrong_region", "valid"]),
  nickname: z.string().nullable(),
  country_code: z.string().nullable(),
  redirect_game: z.object({ slug: z.string(), name: z.string() }).nullable(),
});

export type ValidatePlayerResult = z.infer<typeof ValidatePlayerResultSchema>;
export type ValidatePlayerStatus = ValidatePlayerResult["status"];

export async function validatePlayer(gameId: number, playerId: string, serverId?: string) {
  const path = `/api/games/${gameId}/validate-player`;
  const raw = await apiFetch<unknown>(path, {
    method: "POST",
    body: { player_id: playerId, server_id: serverId || undefined },
  });
  return parseResponse(ValidatePlayerResultSchema, raw, "ValidatePlayerResult", path);
}

/**
 * User-entered contact fields only (ADR-044 decision 4) — request-side
 * validation, client-side UX only, never a security boundary. Mirrors
 * `CreateCheckoutRequest::rules()` exactly (email/max:255,
 * name/max:50, phone/max:32) so this never rejects input the backend
 * would have accepted, or vice versa.
 */
export const CheckoutContactSchema = z.object({
  customer_email: z.string().trim().min(1, "Enter your email address.").email("Enter a valid email address.").max(255),
  customer_name: z.string().trim().min(1, "Enter your full name.").max(50, "Name must be 50 characters or fewer."),
  customer_phone: z.string().trim().min(1, "Enter your phone number.").max(32, "Phone number must be 32 characters or fewer."),
});

export type CheckoutContact = z.infer<typeof CheckoutContactSchema>;

const CheckoutPayloadSchema = z.object({
  game_id: z.number(),
  package_id: z.number(),
  ...CheckoutContactSchema.shape,
  player_id: z.string(),
  server_id: z.string().optional(),
  channel_code: z.string(),
  channel_properties: z.record(z.string(), z.unknown()).optional(),
  /**
   * Generated once per checkout attempt (OrderForm.tsx, when the Review
   * Modal opens) and reused across a resubmit of that same attempt —
   * lets the backend collapse a double-click or client-timeout retry
   * into the original Order instead of creating (and paying for) a
   * second one. See CheckoutController's idempotency_key lookup.
   */
  idempotency_key: z.string(),
  /**
   * ADR-024: only the code itself, never a discount amount (ORD-9) —
   * CheckoutService resolves the real discount server-side from the
   * voucher's own stored remaining/ownership, the same way
   * lib/vouchers.ts's previewVoucher() already does for the Review
   * Modal's own "Apply" preview.
   */
  voucher_code: z.string().optional(),
});

export type CheckoutPayload = z.infer<typeof CheckoutPayloadSchema>;

const CheckoutResultSchema = z.object({
  order_number: z.string(),
  /** Sen, same convention as every money field on the backend (ORD-9) — never computed client-side. */
  final_amount: z.number(),
  payment_status: z.string(),
  /**
   * Xendit's Payment Request v3 `actions` payload, passed through
   * unchanged by CheckoutController — for a redirect-based channel
   * (FPX, confirmed live 2026-07-29) this is really an ARRAY of
   * `{type, descriptor, value}` objects, not a flat object. Left
   * unvalidated/`unknown` here — its real shape isn't confirmed
   * uniform across every channel/gateway yet; extractCheckoutRedirectUrl()
   * below does its own narrow, defensive parsing of it.
   */
  payment_actions: z.unknown(),
});

export type CheckoutResult = z.infer<typeof CheckoutResultSchema>;

export async function submitCheckout(payload: CheckoutPayload) {
  const path = "/api/checkout";
  // Re-validates the full payload right before it leaves the app — the
  // contact fields were already checked against CheckoutContactSchema
  // upstream (OrderForm.tsx), this catches a caller-side bug in the
  // rest of the shape (e.g. a missing idempotency_key) loudly, in dev,
  // instead of round-tripping to the backend to find out.
  const body = CheckoutPayloadSchema.parse(payload);
  const raw = await apiFetch<unknown>(path, { method: "POST", body });
  return parseResponse(CheckoutResultSchema, raw, "CheckoutResult", path);
}

/**
 * Extracts a redirect URL from Xendit's real `actions` shape (an array
 * of `{type, descriptor, value}` — confirmed live against a real
 * MAYB2U_FPX payment request, 2026-07-29) — `descriptor: "WEB_URL"` is
 * the one that means "send the browser here". Also checks the flat
 * `{desktop_web_checkout_url, ...}` object shape some other Xendit
 * product surfaces (Invoices) use, kept as a fallback in case a future
 * channel/gateway returns that instead.
 */
export function extractCheckoutRedirectUrl(actions: unknown): string | null {
  if (Array.isArray(actions)) {
    const webAction = actions.find(
      (action): action is { descriptor?: unknown; value?: unknown } =>
        typeof action === "object" && action !== null && (action as { descriptor?: unknown }).descriptor === "WEB_URL",
    );
    const value = webAction?.value;
    if (typeof value === "string" && value.length > 0) return value;
    return null;
  }

  if (typeof actions === "object" && actions !== null) {
    const keys = ["desktop_web_checkout_url", "mobile_web_checkout_url", "web_checkout_url"];
    for (const key of keys) {
      const value = (actions as Record<string, unknown>)[key];
      if (typeof value === "string" && value.length > 0) return value;
    }
  }

  return null;
}
