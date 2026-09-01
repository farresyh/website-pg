"use client";

import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { getClientSession } from "@/lib/session";

/**
 * ADR-047 decisions 1/3/5 — Reverb client, admin side. Every channel here
 * is PRIVATE (routes/channels.php), authorized against the bearer-token
 * session this app already uses for every other API call (ADR-009 — no
 * cookie/session auth exists) — Echo's default authorizer assumes cookie-
 * based Sanctum SPA auth, which doesn't apply here, so a custom one reads
 * the current token fresh on every subscribe instead (the token can
 * change across this Echo instance's lifetime — login/logout/expiry).
 *
 * A single module-level instance, not one per page — Price Sync/Backups
 * both need a live connection while their own screens are mounted, but
 * the underlying WebSocket should be reused, not torn down and reopened
 * on every navigation between them.
 */
declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * pusher-js's own generator type for this (`ChannelAuthorizerGenerator`)
 * isn't exported from its public entrypoint, so this is typed loosely at
 * its one call site (Echo's `authorizer` option) rather than fighting an
 * unreachable internal type.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
function reverbAuthorizer(channel: { name: string }): any {
  return {
    authorize(socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) {
      const token = getClientSession()?.token;

      fetch(`${API_BASE_URL}/api/broadcasting/auth`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
      })
        .then((response) => (response.ok ? response.json() : Promise.reject(response)))
        .then((data) => callback(null, data))
        .catch(() => callback(new Error("Channel authorization failed"), null));
    },
  };
}

let echo: Echo<"reverb"> | null = null;

export function getEcho(): Echo<"reverb"> {
  if (echo) return echo;

  if (typeof window !== "undefined") {
    window.Pusher = Pusher;
  }

  const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "https";
  const port = Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? (scheme === "https" ? 443 : 80));

  echo = new Echo({
    broadcaster: "reverb",
    key: process.env.NEXT_PUBLIC_REVERB_APP_KEY,
    wsHost: process.env.NEXT_PUBLIC_REVERB_HOST,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === "https",
    enabledTransports: ["ws", "wss"],
    authorizer: reverbAuthorizer,
  });

  return echo;
}
