import { apiFetch } from "@/lib/api-client";

/**
 * ADR-118 V16: a KOL's `?promo=CODE` link. The code is kept in this
 * browser for 7 days for the checkout's promo field, which applies it
 * once the contact details are in (2026-11-07 addendum); it never
 * attributes an order (the server does that from the code on the order).
 * Storage can be blocked or cleared, so every read and write is
 * best-effort.
 */
const STORAGE_KEY = "pg_promo_code";
const CLICKED_KEY = "pg_promo_clicked";
const NOTE_DISMISSED_KEY = "pg_promo_note_dismissed";
const CHANGE_EVENT = "pg-promo-change";
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
  window.dispatchEvent(new Event(CHANGE_EVENT));
}

/** The code has been used, so nothing is left to prefill or announce. */
export function forgetPromoCode(): void {
  try {
    localStorage.removeItem(STORAGE_KEY);
  } catch {
    // Storage blocked: nothing was kept.
  }
  window.dispatchEvent(new Event(CHANGE_EVENT));
}

export function dismissPromoNote(code: string): void {
  try {
    localStorage.setItem(NOTE_DISMISSED_KEY, code);
  } catch {
    // Storage blocked: the note returns on the next page.
  }
  window.dispatchEvent(new Event(CHANGE_EVENT));
}

/** The code the "saved" note announces, unless this visitor closed it. */
export function promoNoteCode(): string | null {
  const code = rememberedPromoCode();
  try {
    return code !== null && localStorage.getItem(NOTE_DISMISSED_KEY) === code ? null : code;
  } catch {
    return code;
  }
}

export function subscribeToPromo(callback: () => void): () => void {
  window.addEventListener(CHANGE_EVENT, callback);
  return () => window.removeEventListener(CHANGE_EVENT, callback);
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
