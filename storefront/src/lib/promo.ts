import { apiFetch } from "@/lib/api-client";

/**
 * ADR-118 V16: a KOL's `?promo=CODE` link. The code is kept in this
 * browser for 7 days to prefill the checkout's promo field; it never
 * attributes an order (the server does that from the code the customer
 * applies). Storage can be blocked or cleared, so every read and write
 * is best-effort.
 */
const STORAGE_KEY = "pg_promo_code";
const CLICKED_KEY = "pg_promo_clicked";
const TTL_MS = 7 * 24 * 60 * 60 * 1000;

/** V17: 3–20 letters and digits; anything else is not a code and is ignored. */
export function normalisePromoCode(raw: string): string | null {
  const code = raw.trim().toUpperCase();
  return /^[A-Z0-9]{3,20}$/.test(code) ? code : null;
}

export function rememberPromoCode(code: string): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ code, expiresAt: Date.now() + TTL_MS }));
  } catch {
    // Storage blocked: the customer can still type the code.
  }
}

export function rememberedPromoCode(): string | null {
  try {
    const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? "null");
    if (saved && typeof saved.code === "string" && typeof saved.expiresAt === "number" && saved.expiresAt > Date.now()) {
      return normalisePromoCode(saved.code);
    }
    localStorage.removeItem(STORAGE_KEY);
  } catch {
    // Unreadable or blocked: no prefill.
  }
  return null;
}

/** One click per tab (sessionStorage), so a reload or page change isn't counted again. */
export function recordPromoClick(code: string): void {
  try {
    if (sessionStorage.getItem(CLICKED_KEY) === code) return;
    sessionStorage.setItem(CLICKED_KEY, code);
  } catch {
    // Blocked: log anyway; the server throttles.
  }
  apiFetch("/api/promo-clicks", { method: "POST", body: { code } }).catch(() => {});
}
