"use client";

/**
 * Mirrors admin/src/hooks/useClientSession.ts's own reasoning:
 * useSyncExternalStore is the SSR-safe way to read a client-only
 * storage value — getServerSnapshot returns null so SSR/first
 * hydration render matches, and React reconciles to the real
 * localStorage value right after hydrating, with no setState-in-effect
 * call for react-hooks/set-state-in-effect to flag.
 */

import { useSyncExternalStore } from "react";
import { getMembershipToken, MEMBERSHIP_TOKEN_CHANGE_EVENT } from "@/lib/membership-session";

function getServerSnapshot(): string | null {
  return null;
}

function subscribe(callback: () => void): () => void {
  window.addEventListener("storage", callback);
  window.addEventListener(MEMBERSHIP_TOKEN_CHANGE_EVENT, callback);
  return () => {
    window.removeEventListener("storage", callback);
    window.removeEventListener(MEMBERSHIP_TOKEN_CHANGE_EVENT, callback);
  };
}

export function useMembershipToken(): string | null {
  return useSyncExternalStore(subscribe, getMembershipToken, getServerSnapshot);
}
