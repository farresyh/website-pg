/**
 * Cookie-based session helpers for the "optimistic check" pattern
 * (see Next.js Proxy docs: proxy can redirect based on cookie presence,
 * but must never be the real authorization boundary — Laravel enforces
 * that on every request. See foundation-security.md §1.)
 *
 * The cookie itself is set by app/api/login/route.ts and is presence-only
 * (proxy.ts only checks `.has()`, never reads a value out of it). The real
 * login is Laravel's own httpOnly session cookie on the API host (ADR-121).
 * `SessionPayload` is the cached admin profile in lib/session.ts, not the
 * cookie's contents.
 *
 * TODO: decode `role` client-side to drive the /middleware optimistic gate
 * in proxy.ts (Super-Admin-only area, per §3 Users & Roles) — not needed
 * yet since /middleware has no real screens built behind it.
 */

export const SESSION_COOKIE_NAME = "kerox_session";

export type MfaStatus = "none" | "pending" | "active";

export interface SessionPayload {
  id: number;
  role: "super_admin" | "admin";
  name: string;
  email: string;
  mfa_status: MfaStatus;
}

/** The `admin` object Laravel returns from login and `/api/me`. */
export function toSessionPayload(admin: SessionPayload): SessionPayload {
  const { id, role, name, email, mfa_status } = admin;
  return { id, role, name, email, mfa_status };
}
