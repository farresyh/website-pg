"use client";

import { useMemo, useSyncExternalStore } from "react";

/**
 * A signed-out visitor's place in the sign-in: which plan they chose,
 * their email, and whether the code was sent. In sessionStorage, not
 * component state, because reading the code means leaving for the mail
 * app, and a phone's in-app browser can reload the page on the way back.
 */
export interface PendingSignIn {
  step: "email" | "otp";
  email: string;
  planId: number | null;
}

const KEY = "krs_membership_signin";
const CHANGE_EVENT = "krs-membership-signin-change";

function read(): string | null {
  try {
    return sessionStorage.getItem(KEY);
  } catch {
    return null;
  }
}

function write(value: string | null): void {
  try {
    if (value === null) sessionStorage.removeItem(KEY);
    else sessionStorage.setItem(KEY, value);
  } catch {
    // Blocked storage: the sign-in still works, it just can't survive a reload.
  }
  window.dispatchEvent(new Event(CHANGE_EVENT));
}

export function savePendingSignIn(pending: PendingSignIn): void {
  write(JSON.stringify(pending));
}

export function clearPendingSignIn(): void {
  write(null);
}

function subscribe(callback: () => void): () => void {
  window.addEventListener(CHANGE_EVENT, callback);
  return () => window.removeEventListener(CHANGE_EVENT, callback);
}

export function usePendingSignIn(): PendingSignIn | null {
  const raw = useSyncExternalStore(subscribe, read, () => null);

  return useMemo(() => {
    try {
      return raw ? (JSON.parse(raw) as PendingSignIn) : null;
    } catch {
      return null;
    }
  }, [raw]);
}
