"use client";

import { useEffect } from "react";
import { getEcho } from "@/lib/echo";

/**
 * ADR-047 2026-09-28 addendum. Price Sync/Backups/Orders all dropped
 * polling for push-only (decision 4's own "accepted degrade: if
 * Reverb isn't reachable, this screen sits at its last-known state
 * until the admin navigates away and back") — but nothing actually
 * did that "navigate away and back" automatically. Fires `callback`
 * when the tab becomes visible again (a backgrounded tab's WS may
 * have been silently dropped without ever emitting 'disconnected' —
 * visibilitychange fires immediately on resume regardless) and when
 * Echo's own connection comes back to `connected` (a network blip
 * while the tab stayed foreground, which visibilitychange never sees).
 * Same hook as storefront's own copy — duplicated, not shared, since
 * these are two separate Next.js apps with no shared package today.
 */
export function useReconcileOnResume(callback: () => void): void {
  useEffect(() => {
    function onVisibilityChange() {
      if (document.visibilityState === "visible") callback();
    }
    document.addEventListener("visibilitychange", onVisibilityChange);

    if (!process.env.NEXT_PUBLIC_REVERB_APP_KEY) {
      return () => document.removeEventListener("visibilitychange", onVisibilityChange);
    }

    const connection = getEcho().connector.pusher.connection;
    connection.bind("connected", callback);

    return () => {
      document.removeEventListener("visibilitychange", onVisibilityChange);
      connection.unbind("connected", callback);
    };
  }, [callback]);
}
