"use client";

/**
 * ADR-038 decision 8: centralizes the SSR-safe session-read shape that
 * used to be duplicated as `useState<SessionPayload|null>(null)` +
 * `useEffect(() => setSession(getClientSession()), [])` across ~24 call
 * sites (see UserDropdown.tsx's original comment for why a render-body
 * read isn't safe — sessionStorage doesn't exist during SSR, so it
 * produces a server/client text mismatch).
 *
 * useSyncExternalStore is the framework-provided fix: getServerSnapshot
 * returns null so SSR/first hydration render matches, and React itself
 * (not a manually-written effect) reconciles to the real client value
 * right after hydrating — no setState-in-effect call for the lint rule
 * to flag. subscribe() also covers same-tab login/logout, which a bare
 * "storage" listener wouldn't (that event only fires in *other* tabs).
 */

import { useSyncExternalStore } from "react";
import { getClientSession, SESSION_CHANGE_EVENT } from "@/lib/session";
import type { SessionPayload } from "@/lib/auth";

let cachedJson: string | null = null;
let cachedSession: SessionPayload | null = null;

function getSnapshot(): SessionPayload | null {
  const session = getClientSession();
  const json = session ? JSON.stringify(session) : null;
  if (json !== cachedJson) {
    cachedJson = json;
    cachedSession = session;
  }
  return cachedSession;
}

function getServerSnapshot(): SessionPayload | null {
  return null;
}

function subscribe(callback: () => void): () => void {
  window.addEventListener("storage", callback);
  window.addEventListener(SESSION_CHANGE_EVENT, callback);
  return () => {
    window.removeEventListener("storage", callback);
    window.removeEventListener(SESSION_CHANGE_EVENT, callback);
  };
}

export function useClientSession(): SessionPayload | null {
  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
