/**
 * Client-side counterpart to the presence-only httpOnly cookie in
 * `auth.ts`. Per ADR-009 the portal calls the Laravel API directly from
 * the browser with a Bearer token — it has to live somewhere JS can
 * read, which an httpOnly cookie deliberately can't. `sessionStorage`
 * (cleared on tab close) is the minimal choice, not `localStorage`.
 * Mirrors `admin/src/lib/session.ts`.
 */

import type { ResellerSessionPayload } from "@/lib/auth";

const STORAGE_KEY = "kerox_reseller_session";

// Fired on same-tab writes so useClientSession() picks up a login/logout
// immediately — the native "storage" event only fires in *other* tabs.
export const SESSION_CHANGE_EVENT = "kerox-reseller-session-change";

export function getClientSession(): ResellerSessionPayload | null {
  if (typeof window === "undefined") return null;

  const raw = window.sessionStorage.getItem(STORAGE_KEY);
  if (!raw) return null;

  try {
    return JSON.parse(raw) as ResellerSessionPayload;
  } catch {
    return null;
  }
}

export function setClientSession(session: ResellerSessionPayload): void {
  window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(session));
  window.dispatchEvent(new Event(SESSION_CHANGE_EVENT));
}

export function clearClientSession(): void {
  window.sessionStorage.removeItem(STORAGE_KEY);
  window.dispatchEvent(new Event(SESSION_CHANGE_EVENT));
}
