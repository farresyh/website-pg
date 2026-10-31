"use client";

import Script from "next/script";
import { useEffect, useRef, useState } from "react";

const SITE_KEY = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY ?? "";

interface TurnstileApi {
  render: (el: HTMLElement, options: Record<string, unknown>) => string;
  reset: (widgetId: string) => void;
  remove: (widgetId: string) => void;
}

declare global {
  interface Window {
    turnstile?: TurnstileApi;
  }
}

export const turnstileConfigured = SITE_KEY !== "";

/**
 * Cloudflare Turnstile, the same widget as the storefront's Game ID Checker (ADR-123). Reports a
 * fresh single-use token, `null` when it expires, and an error (for example a hostname missing from
 * the widget) so the form can say so instead of waiting forever. `resetKey` changes force a new token.
 */
export default function TurnstileWidget({ onToken, onError, resetKey }: { onToken: (token: string | null) => void; onError: () => void; resetKey: number }) {
  const [scriptReady, setScriptReady] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const widgetId = useRef<string | null>(null);
  const handlers = useRef({ onToken, onError });

  useEffect(() => {
    handlers.current = { onToken, onError };
  });

  useEffect(() => {
    if (!scriptReady || !SITE_KEY || !ref.current || !window.turnstile || widgetId.current) return;
    widgetId.current = window.turnstile.render(ref.current, {
      sitekey: SITE_KEY,
      appearance: "interaction-only",
      callback: (t: string) => handlers.current.onToken(t),
      "expired-callback": () => handlers.current.onToken(null),
      "error-callback": () => {
        handlers.current.onToken(null);
        handlers.current.onError();
      },
    });
    return () => {
      if (widgetId.current) window.turnstile?.remove(widgetId.current);
      widgetId.current = null;
    };
  }, [scriptReady]);

  useEffect(() => {
    if (resetKey > 0 && widgetId.current) window.turnstile?.reset(widgetId.current);
  }, [resetKey]);

  return (
    <>
      <Script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" strategy="afterInteractive" onReady={() => setScriptReady(true)} />
      <div ref={ref} />
    </>
  );
}
