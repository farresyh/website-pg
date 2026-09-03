"use client";

/**
 * `useSyncExternalStore` is the SSR-safe way to read a client-only
 * value — `getServerSnapshot` returns null so SSR / the first hydration
 * render match, then React reconciles to the real value right after
 * hydrating, with no setState-in-effect for `react-hooks/set-state-in-
 * effect` to flag.
 *
 * ADR-071 PR2b: the token is a cookie now (`lib/membership-session.ts`).
 * Cookies have no native change event, so only the same-tab custom
 * event drives reactivity here — a sign-in/out in *another* tab isn't
 * reflected until this tab navigates. That's an accepted edge (the
 * verify flow and shopping happen in one tab); the order page's SSR
 * read via `MemberAwareOrderForm` is the authoritative source anyway.
 */

import { useSyncExternalStore } from "react";
import { getMembershipToken, MEMBERSHIP_TOKEN_CHANGE_EVENT } from "@/lib/membership-session";

function getServerSnapshot(): string | null {
  return null;
}

function subscribe(callback: () => void): () => void {
  window.addEventListener(MEMBERSHIP_TOKEN_CHANGE_EVENT, callback);
  return () => {
    window.removeEventListener(MEMBERSHIP_TOKEN_CHANGE_EVENT, callback);
  };
}

export function useMembershipToken(): string | null {
  return useSyncExternalStore(subscribe, getMembershipToken, getServerSnapshot);
}
