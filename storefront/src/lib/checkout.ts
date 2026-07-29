import { apiFetch } from "@/lib/api-client";

/**
 * Real request/response contracts for two backend endpoints —
 * POST /api/games/{game}/validate-player (PlayerValidationController)
 * and POST /api/checkout (CheckoutController). `gameId`/`packageId`
 * now come from the real public catalog (lib/catalog.ts), not
 * placeholder data.
 */

export type ValidatePlayerStatus = "invalid" | "region_unknown" | "wrong_region" | "valid";

export interface ValidatePlayerResult {
  status: ValidatePlayerStatus;
  nickname: string | null;
  country_code: string | null;
  redirect_game: { slug: string; name: string } | null;
}

export function validatePlayer(gameId: number, playerId: string, serverId?: string) {
  return apiFetch<ValidatePlayerResult>(`/api/games/${gameId}/validate-player`, {
    method: "POST",
    body: { player_id: playerId, server_id: serverId || undefined },
  });
}

export interface CheckoutPayload {
  game_id: number;
  package_id: number;
  customer_email: string;
  customer_name: string;
  customer_phone?: string;
  player_id: string;
  server_id?: string;
  channel_code: string;
  channel_properties?: Record<string, unknown>;
  /**
   * Generated once per checkout attempt (OrderForm.tsx, when the Review
   * Modal opens) and reused across a resubmit of that same attempt —
   * lets the backend collapse a double-click or client-timeout retry
   * into the original Order instead of creating (and paying for) a
   * second one. See CheckoutController's idempotency_key lookup.
   */
  idempotency_key: string;
}

export interface CheckoutResult {
  order_number: string;
  /** Sen, same convention as every money field on the backend (ORD-9) — never computed client-side. */
  final_amount: number;
  payment_status: string;
  /**
   * Xendit's Payment Request v3 `actions` payload, passed through
   * unchanged by CheckoutController — for a redirect-based channel
   * (FPX, confirmed live 2026-07-29) this is really an ARRAY of
   * `{type, descriptor, value}` objects (e.g.
   * `[{type:"REDIRECT_CUSTOMER", descriptor:"WEB_URL", value:"https://..."}]`),
   * not a flat object. Typed `unknown` rather than a specific shape
   * since it isn't confirmed uniform across every channel/gateway yet.
   */
  payment_actions: unknown;
}

export function submitCheckout(payload: CheckoutPayload) {
  return apiFetch<CheckoutResult>("/api/checkout", { method: "POST", body: payload });
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
