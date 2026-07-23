/**
 * Cookie-based session helpers for the "optimistic check" pattern
 * (see Next.js Proxy docs: proxy can redirect based on cookie presence,
 * but must never be the real authorization boundary — Laravel enforces
 * that on every request. See foundation-security.md §1.)
 *
 * TODO once Laravel Sanctum login endpoint (AUTH-1/AUTH-2) exists:
 * - set this cookie on successful login via a Route Handler that calls
 *   the Laravel /login endpoint and stores the returned token httpOnly.
 * - decode `role` from the token/session to drive the /middleware
 *   optimistic gate in proxy.ts (Super-Admin-only area, per §3 Users & Roles).
 */

export const SESSION_COOKIE_NAME = "kerox_session";

export interface SessionPayload {
  token: string;
  role: "super_admin" | "admin";
}
