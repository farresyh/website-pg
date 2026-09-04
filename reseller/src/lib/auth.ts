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

export interface AffiliateSessionPayload {
  token: string;
  affiliate_user_id: number;
  affiliate_id: number;
  name: string;
  email: string;
  business_name: string;
  // Set only when this session was started by an admin impersonating
  // the affiliate (ADR-058 RES-4 / ADR-059 59c). Drives the persistent
  // "Impersonating …" banner and the Exit action.
  impersonating?: boolean;
  admin_name?: string | null;
  impersonation_session_id?: number;
}
