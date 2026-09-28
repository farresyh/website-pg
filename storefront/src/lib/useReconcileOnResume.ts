"use client";

import { useEffect } from "react";
import { getEcho } from "@/lib/echo";

/**
 * ADR-047 2026-09-28 addendum. Every push-based screen in this app
 * trusts a single WebSocket delivery with no replay — if it's missed
 * (mobile tab backgrounded then resumed, a network blip, a resubscribe
 * race), nothing re-checks the truth again on its own. This is the one
 * shared "reconcile now" trigger: fires `callback` when the tab
 * becomes visible again (covers a backgrounded tab, whose WS may have
 * been silently dropped without ever emitting a 'disconnected' event —
 * `visibilitychange` fires immediately on resume regardless) and when
 * Echo's own connection comes back to `connected` (covers a network
 * blip while the tab stayed foreground, which visibilitychange never
 * sees). Doesn't touch, or replace, whatever polling/push logic the
 * caller already has — this is a pure addition on top.
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
