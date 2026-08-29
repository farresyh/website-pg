/**
 * ADR-027's 2026-08-29 addendum, decisions 17/23: the 30-day
 * "verified session" token, stored client-side. Deliberately
 * `localStorage`, not `sessionStorage` (admin/src/lib/session.ts's own
 * choice, cleared on tab close) — the whole point of decision 23's
 * rolling window is that a returning customer does NOT need to
 * re-verify on every visit, so this must survive across browser
 * sessions, not just one tab's lifetime.
 */

const STORAGE_KEY = "krs_membership_token";

// Fired on same-tab writes so useMembershipToken() picks up a
// verify/sign-out immediately — the native "storage" event only fires
// in *other* tabs, never the tab that made the change (same reasoning
// as admin/src/lib/session.ts's own SESSION_CHANGE_EVENT).
export const MEMBERSHIP_TOKEN_CHANGE_EVENT = "krs-membership-token-change";

export function getMembershipToken(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(STORAGE_KEY);
}

export function setMembershipToken(token: string): void {
  window.localStorage.setItem(STORAGE_KEY, token);
  window.dispatchEvent(new Event(MEMBERSHIP_TOKEN_CHANGE_EVENT));
}

export function clearMembershipToken(): void {
  window.localStorage.removeItem(STORAGE_KEY);
  window.dispatchEvent(new Event(MEMBERSHIP_TOKEN_CHANGE_EVENT));
}
