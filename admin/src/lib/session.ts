/**
 * Client-side counterpart to the httpOnly SESSION_COOKIE_NAME cookie in
 * auth.ts. Per ADR-009, the Admin Panel calls the Laravel API directly from
 * the browser using a Bearer token — that token has to live somewhere JS can
 * read it, which an httpOnly cookie deliberately can't be. sessionStorage
 * (cleared on tab close) is the minimal choice here, not localStorage.
 *
 * The httpOnly cookie stays presence-only (see app/api/login/route.ts) and
 * is never the source of the token — it only drives proxy.ts's optimistic
 * redirect gate, which is explicitly not a security boundary.
 */

import type { SessionPayload } from "@/lib/auth";

const STORAGE_KEY = "kerox_admin_session";

export function getClientSession(): SessionPayload | null {
  if (typeof window === "undefined") return null;

  const raw = window.sessionStorage.getItem(STORAGE_KEY);
  if (!raw) return null;

  try {
    return JSON.parse(raw) as SessionPayload;
  } catch {
    return null;
  }
}

export function setClientSession(session: SessionPayload): void {
  window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(session));
}

export function clearClientSession(): void {
  window.sessionStorage.removeItem(STORAGE_KEY);
}
