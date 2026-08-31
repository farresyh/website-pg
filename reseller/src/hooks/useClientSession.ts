"use client";

/**
 * SSR-safe session read (mirrors `admin/src/hooks/useClientSession.ts`,
 * ADR-038 decision 8). `useSyncExternalStore` is the framework fix for
 * the `react-hooks/set-state-in-effect` trap: `getServerSnapshot`
 * returns null so SSR/first hydration matches, then React reconciles to
 * the real client value — no setState-in-effect. `subscribe()` also
 * covers same-tab login/logout (a bare "storage" listener wouldn't —
 * that event only fires in *other* tabs).
 */

import { useSyncExternalStore } from "react";
import { getClientSession, SESSION_CHANGE_EVENT } from "@/lib/session";
import type { ResellerSessionPayload } from "@/lib/auth";

let cachedJson: string | null = null;
let cachedSession: ResellerSessionPayload | null = null;

function getSnapshot(): ResellerSessionPayload | null {
  const session = getClientSession();
  const json = session ? JSON.stringify(session) : null;
  if (json !== cachedJson) {
    cachedJson = json;
    cachedSession = session;
  }
  return cachedSession;
}

function getServerSnapshot(): ResellerSessionPayload | null {
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

export function useClientSession(): ResellerSessionPayload | null {
  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
