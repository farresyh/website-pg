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
}

export interface CheckoutResult {
  order_number: string;
  /** Sen, same convention as every money field on the backend (ORD-9) — never computed client-side. */
  final_amount: number;
  payment_status: string;
  /** Opaque Xendit "actions" payload — shape varies per channel (redirect URL, QR string, etc.). */
  payment_actions: Record<string, unknown>;
}

export function submitCheckout(payload: CheckoutPayload) {
  return apiFetch<CheckoutResult>("/api/checkout", { method: "POST", body: payload });
}

/** Xendit's actions payload uses different keys per channel — try the common redirect-URL ones in order. */
export function extractCheckoutRedirectUrl(actions: Record<string, unknown>): string | null {
  const keys = ["desktop_web_checkout_url", "mobile_web_checkout_url", "web_checkout_url"];
  for (const key of keys) {
    const value = actions[key];
    if (typeof value === "string" && value.length > 0) return value;
  }
  return null;
}
