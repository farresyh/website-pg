/**
 * Cookie + client-session shapes for the reseller portal. Mirrors
 * `admin/src/lib/auth.ts` — the httpOnly cookie is presence-only (drives
 * `proxy.ts`'s optimistic redirect, never a security boundary; Laravel's
 * `reseller` guard enforces on every request), the real bearer token
 * lives in `sessionStorage` (`lib/session.ts`), per ADR-009.
 */

export const SESSION_COOKIE_NAME = "kerox_reseller_session";

export interface ResellerSessionPayload {
  token: string;
  reseller_user_id: number;
  reseller_id: number;
  name: string;
  email: string;
  business_name: string;
  // Set only when this session was started by an admin impersonating
  // the reseller (ADR-058 RES-4 / ADR-059 59c). Drives the persistent
  // "Impersonating …" banner and the Exit action.
  impersonating?: boolean;
  admin_name?: string | null;
  impersonation_session_id?: number;
}
