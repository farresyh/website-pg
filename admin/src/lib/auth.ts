/**
 * Cookie-based session helpers for the "optimistic check" pattern
 * (see Next.js Proxy docs: proxy can redirect based on cookie presence,
 * but must never be the real authorization boundary — Laravel enforces
 * that on every request. See foundation-security.md §1.)
 *
 * The cookie itself is set by app/api/login/route.ts and is presence-only
 * (proxy.ts only checks `.has()`, never reads a value out of it) — the real
 * token lives in sessionStorage via lib/session.ts, per ADR-009's
 * browser-calls-Laravel-directly design. `SessionPayload` here is the shape
 * of *that* client-side session, not the cookie's contents.
 *
 * TODO: decode `role` client-side to drive the /middleware optimistic gate
 * in proxy.ts (Super-Admin-only area, per §3 Users & Roles) — not needed
 * yet since /middleware has no real screens built behind it.
 */

export const SESSION_COOKIE_NAME = "kerox_session";

export interface SessionPayload {
  token: string;
  id: number;
  role: "super_admin" | "admin";
  name: string;
  email: string;
}
