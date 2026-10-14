/**
 * The signed-in admin's profile (id, role, name, email, MFA status), cached
 * in the browser so the UI can render role-aware screens without a round
 * trip. ADR-121: this holds no credential — the login itself is Laravel's
 * httpOnly session cookie, and every API call is authorized by it. The
 * cache is in localStorage so every tab shares it, like the login.
 */

import type { SessionPayload } from "@/lib/auth";

const STORAGE_KEY = "kerox_admin_session";

// Fired on same-tab writes so useClientSession() (see hooks/) picks up a
// login/logout immediately — the native "storage" event only fires in
// *other* tabs, never the tab that made the change.
export const SESSION_CHANGE_EVENT = "kerox-admin-session-change";

function storage(): Storage | null {
  try {
    return typeof window === "undefined" ? null : window.localStorage;
  } catch {
    return null;
  }
}

export function getClientSession(): SessionPayload | null {
  const raw = storage()?.getItem(STORAGE_KEY);
  if (!raw) return null;

  try {
    return JSON.parse(raw) as SessionPayload;
  } catch {
    return null;
  }
}

export function setClientSession(session: SessionPayload): void {
  storage()?.setItem(STORAGE_KEY, JSON.stringify(session));
  window.dispatchEvent(new Event(SESSION_CHANGE_EVENT));
}

export function clearClientSession(): void {
  storage()?.removeItem(STORAGE_KEY);
  window.dispatchEvent(new Event(SESSION_CHANGE_EVENT));
}
