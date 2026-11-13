"use client";

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { getBotStatus } from "@/lib/portal";

const POLL_MS = 60_000;

/**
 * ADR-075 2026-11-14 addendum, decision 4: asks every minute whether the
 * WhatsApp bot is degraded, so the banner appears during an outage and goes
 * away after it without a reload. Only while `enabled` (a reseller with a
 * bot group). A failed check keeps the last answer: the banner is help,
 * never a reason to break the screen.
 */
export function useBotDegraded(enabled: boolean): boolean {
  const [degraded, setDegraded] = useState(false);

  useEffect(() => {
    if (!enabled) return;
    let cancelled = false;

    const check = () => {
      const session = getClientSession();
      if (!session) return;
      getBotStatus(session.token)
        .then((data) => {
          if (!cancelled) setDegraded(data.degraded);
        })
        .catch(() => {});
    };

    check();
    const timer = setInterval(check, POLL_MS);

    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, [enabled]);

  return enabled && degraded;
}
