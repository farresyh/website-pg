"use client";

import Echo from "laravel-echo";
import Pusher from "pusher-js";

/**
 * ADR-047 decisions 2/5 — Reverb client, storefront side. Guest checkout
 * (ADR-011) means this file never authenticates a private channel: every
 * subscription made through it is on a public `order.{order_number}`
 * channel, the same order_number-as-secret trust boundary trackOrder()
 * already relies on (see backend/app/Events/OrderStatusUpdated.php's own
 * doc comment).
 *
 * `NEXT_PUBLIC_REVERB_APP_KEY`/`HOST`/`PORT`/`SCHEME` are required —
 * not set yet anywhere (`.env.local` is out of this session's write
 * access, per this repo's own established pattern for real secrets/config
 * values). Local dev: point at `php artisan reverb:start`'s default
 * (`NEXT_PUBLIC_REVERB_HOST=localhost`, `_PORT=8080`, `_SCHEME=http`).
 * Production: the real domain, port 443, scheme https — reaches Reverb via
 * `nginx`'s `/app/` proxy (docker-compose.prod.yml), never a direct port.
 *
 * A single module-level instance, not one per component — components like
 * OrderStatusTracker mount/unmount per page, but the underlying WebSocket
 * connection should be reused across them, not torn down and reopened.
 */
declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
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
  });

  return echo;
}
