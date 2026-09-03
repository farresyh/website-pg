/**
 * ADR-027's 2026-08-29 addendum, decisions 17/23: the 30-day "verified
 * session" token.
 *
 * ADR-071 PR2 (an ADR-027 addendum): stored in a **cookie**, not
 * `localStorage` — so the order-page RSC can read it and personalize
 * member pricing + resolve the member's email in the single server
 * render, instead of `OrderForm` firing a second client-side
 * `getGamePackages(token)` + `getMe(token)` wave from a `useEffect` on
 * every load. Exposure is unchanged from `localStorage` (the token was
 * always JS-readable); `SameSite=Lax` + `Secure` (in prod) is if
 * anything a small improvement.
 *
 * Still a client-set cookie (not `httpOnly`): the checkout submit needs
 * to read it to send as the Bearer token on `POST /api/checkout`, and
 * `MembershipClient` needs to set/clear it after OTP verify / sign-out.
 */

const COOKIE_NAME = "krs_membership_token";
const MAX_AGE_SECONDS = 60 * 60 * 24 * 30; // 30 days, matches decision 23's rolling window

// Fired on same-tab writes so useMembershipToken() picks up a
// verify/sign-out immediately — cookies have no native change event at
// all (unlike `localStorage`'s cross-tab "storage" event).
export const MEMBERSHIP_TOKEN_CHANGE_EVENT = "krs-membership-token-change";

export function getMembershipToken(): string | null {
  if (typeof document === "undefined") return null;
  const match = document.cookie.match(/(?:^|;\s*)krs_membership_token=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
}

export function setMembershipToken(token: string): void {
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `${COOKIE_NAME}=${encodeURIComponent(token)}; Path=/; Max-Age=${MAX_AGE_SECONDS}; SameSite=Lax${secure}`;
  window.dispatchEvent(new Event(MEMBERSHIP_TOKEN_CHANGE_EVENT));
}

export function clearMembershipToken(): void {
  document.cookie = `${COOKIE_NAME}=; Path=/; Max-Age=0; SameSite=Lax`;
  window.dispatchEvent(new Event(MEMBERSHIP_TOKEN_CHANGE_EVENT));
}
