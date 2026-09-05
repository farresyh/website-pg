/**
 * Cookie + client-session shapes for the reseller portal. Mirrors
 * `admin/src/lib/auth.ts` — the httpOnly cookie is presence-only (drives
 * `proxy.ts`'s optimistic redirect, never a security boundary; Laravel's
 * `affiliate` guard enforces on every request), the real bearer token
 * lives in `sessionStorage` (`lib/session.ts`), per ADR-009.
 */

// ADR-072 PR-A: kept as-is deliberately — renaming the storage/cookie key
// would only matter if we needed to invalidate every existing session, and
// doing it without matching every read+write site is exactly how a session
// gets silently orphaned. Not renamed.
export const SESSION_COOKIE_NAME = "kerox_reseller_session";

/**
 * ADR-072 decision 5 / PR-G: this same session shape now covers both
 * account types the `affiliate` Sanctum guard authenticates — an
 * `Affiliate` (whitelabel) portal user AND a `Reseller` (wallet) portal
 * user (PR-G planning addendum decision 6, same login endpoint). Every
 * screen branches on `owner_type`, never on which key of the login
 * response happened to be non-null.
 */
export interface AffiliateSessionPayload {
  token: string;
  affiliate_user_id: number;
  owner_type: "affiliate" | "reseller";
  owner_id: number;
  name: string;
  email: string;
  business_name: string;
  // Set only when this session was started by an admin impersonating
  // the affiliate (ADR-058 RES-4 / ADR-059 59c). Drives the persistent
  // "Impersonating …" banner and the Exit action. ADR-072/PR-G decision
  // 10: never set for owner_type "reseller" — impersonation is not
  // extended to Reseller (wallet) accounts.
  impersonating?: boolean;
  admin_name?: string | null;
  impersonation_session_id?: number;
}
