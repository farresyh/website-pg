"use client";

import Script from "next/script";
import Image from "next/image";
import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { CheckCircle, WarningCircle, XCircle, Hourglass, ArrowRight } from "@phosphor-icons/react/dist/ssr";
import PlaceholderTile from "@/components/ui/PlaceholderTile";
import Button from "@/components/ui/Button";
import { ApiError } from "@/lib/api-client";
import { checkPlayer, orderHrefWithPrefill, type PlayerCheckFamily, type PlayerCheckResult } from "@/lib/player-check";
import { digitsOnlyHandlers, idInputClass, idLabelClass, inputError } from "@/components/order/idFields";
import { guideFor } from "@/components/player-check/guides";

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

/**
 * The Game ID Checker form for one family (ADR-123). Every check carries
 * a fresh Cloudflare Turnstile token (single-use, reset after each try);
 * the backend refuses a check without one. The result names our store
 * and links to it with the ID prefilled, never validated on arrival.
 */
export default function PlayerCheckClient({ family, storeName }: { family: PlayerCheckFamily; storeName: string }) {
  const guide = guideFor(family.key);
  const [playerId, setPlayerId] = useState("");
  const [serverId, setServerId] = useState("");
  const [token, setToken] = useState<string | null>(null);
  // Turnstile reports an error (e.g. 110200: this hostname is not on the widget) through
  // error-callback and never produces a token, so without this the form waits forever.
  const [verifyFailed, setVerifyFailed] = useState(false);
  const [scriptReady, setScriptReady] = useState(false);
  const [checking, setChecking] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<PlayerCheckResult | null>(null);
  const widgetRef = useRef<HTMLDivElement>(null);
  const widgetId = useRef<string | null>(null);

  const idKind = family.player_id_format === "text" ? "text" : "digits";
  const playerIdError = inputError(playerId, guide.idLabel, idKind);
  const serverIdError = family.requires_server_id ? inputError(serverId, "Server ID", "digits") : null;
  const ready =
    playerId.trim() !== "" &&
    playerIdError === null &&
    (!family.requires_server_id || (serverId.trim() !== "" && serverIdError === null));

  useEffect(() => {
    if (!scriptReady || !SITE_KEY || !widgetRef.current || !window.turnstile || widgetId.current) return;
    widgetId.current = window.turnstile.render(widgetRef.current, {
      sitekey: SITE_KEY,
      appearance: "interaction-only",
      callback: (t: string) => {
        setToken(t);
        setVerifyFailed(false);
      },
      "expired-callback": () => setToken(null),
      "error-callback": () => {
        setToken(null);
        setVerifyFailed(true);
      },
    });
    return () => {
      if (widgetId.current) window.turnstile?.remove(widgetId.current);
      widgetId.current = null;
    };
  }, [scriptReady]);

  const resetToken = useCallback(() => {
    setToken(null);
    if (widgetId.current) window.turnstile?.reset(widgetId.current);
  }, []);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!ready || !token) return;
    setChecking(true);
    setError(null);
    setResult(null);
    try {
      setResult(await checkPlayer(family.slug, playerId.trim(), family.requires_server_id ? serverId.trim() : undefined, token));
    } catch (err) {
      if (err instanceof ApiError && err.status === 429) {
        setError("Too many checks from your connection. Wait a minute and try again.");
      } else if (err instanceof ApiError && err.status === 503) {
        setError("The Game ID Checker is not available right now. Please try again later.");
      } else {
        setError(err instanceof ApiError ? err.message : "Couldn't reach the checker. Please try again in a moment.");
      }
    } finally {
      setChecking(false);
      resetToken();
    }
  }

  if (!SITE_KEY) {
    return (
      <p className="rounded-md border-2 border-ink bg-surface-container p-4 text-sm text-on-surface-variant">
        The Game ID Checker is not available right now. Please try again later.
      </p>
    );
  }

  return (
    <div>
      <Script
        src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit"
        strategy="afterInteractive"
        onReady={() => setScriptReady(true)}
      />

      <form onSubmit={handleSubmit} className="rounded-lg border-2 border-ink bg-surface-container-lowest p-5 neo">
        <div className={`grid gap-3 ${family.requires_server_id ? "grid-cols-1 sm:grid-cols-2" : "grid-cols-1"}`}>
          <div>
            <label htmlFor="checkPlayerId" className={idLabelClass}>
              {guide.idLabel}
            </label>
            <input
              id="checkPlayerId"
              type="text"
              value={playerId}
              onChange={(e) => setPlayerId(e.target.value)}
              {...(idKind === "digits" ? digitsOnlyHandlers : {})}
              placeholder={guide.idPlaceholder}
              inputMode={idKind === "digits" ? "numeric" : "text"}
              autoComplete="off"
              aria-invalid={playerIdError !== null}
              aria-describedby={playerIdError ? "checkPlayerIdError" : undefined}
              className={`${idInputClass} w-full font-mono`}
            />
            {playerIdError && (
              <p id="checkPlayerIdError" role="alert" className="mt-1.5 text-[13px] font-medium text-danger">
                {playerIdError}
              </p>
            )}
          </div>
          {family.requires_server_id && (
            <div>
              <label htmlFor="checkServerId" className={idLabelClass}>
                Server ID
              </label>
              <input
                id="checkServerId"
                type="text"
                value={serverId}
                onChange={(e) => setServerId(e.target.value)}
                {...digitsOnlyHandlers}
                placeholder={guide.serverPlaceholder ?? "e.g. 1234"}
                inputMode="numeric"
                autoComplete="off"
                aria-invalid={serverIdError !== null}
                aria-describedby={serverIdError ? "checkServerIdError" : undefined}
                className={`${idInputClass} w-full font-mono`}
              />
              {serverIdError && (
                <p id="checkServerIdError" role="alert" className="mt-1.5 text-[13px] font-medium text-danger">
                  {serverIdError}
                </p>
              )}
            </div>
          )}
        </div>

        <div ref={widgetRef} className="mt-3" />

        <div className="mt-4 flex items-center justify-between gap-3">
          <p className="text-xs text-on-surface-variant">{token
              ? "Ready to check."
              : verifyFailed
                ? "We couldn't verify you on this page. Please reload, or try again later."
                : "Verifying you're human…"}</p>
          <Button type="submit" disabled={checking || !ready || !token}>
            {checking ? "Checking…" : "Check ID"}
          </Button>
        </div>
      </form>

      <div aria-live="polite">
        {error && (
          <p className="mt-4 rounded-md border-2 border-ink bg-surface-container p-4 text-sm text-on-surface-variant">{error}</p>
        )}
        {result && <ResultCard result={result} storeName={storeName} />}
      </div>
    </div>
  );
}

function ResultCard({ result, storeName }: { result: PlayerCheckResult; storeName: string }) {
  const box = "mt-4 rounded-md border-2 border-ink p-4 text-sm";

  switch (result.status) {
    case "found":
      return (
        <div className={`${box} bg-surface-container-lowest neo`}>
          <p className="flex items-center gap-2 font-display text-base font-bold text-on-surface">
            <CheckCircle size={20} weight="fill" className="shrink-0 text-success" />
            Account found{result.nickname ? `: ${result.nickname}` : ""}
          </p>
          {result.zone && <p className="mt-1 text-on-surface-variant">Server: {result.zone}</p>}
          <p className="mt-2 text-on-surface-variant">
            This account tops up at {result.stores.map((s) => s.name).join(" or ")}.
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            {result.stores.map((store) => (
              <Button key={store.slug} href={orderHrefWithPrefill(store.slug, result.player_id, result.server_id)}>
                Top up {store.name}
              </Button>
            ))}
          </div>
        </div>
      );
    case "unsupported_region":
      return (
        <p className={`${box} flex items-start gap-2 bg-warning text-on-warning`}>
          <WarningCircle size={18} weight="fill" className="mt-0.5 shrink-0" />
          <span>
            Account found{result.nickname ? `: ${result.nickname}` : ""}. We don&apos;t sell top-ups for this
            account&apos;s region yet.
          </span>
        </p>
      );
    case "unavailable_here":
      return (
        <p className={`${box} flex items-start gap-2 bg-warning text-on-warning`}>
          <WarningCircle size={18} weight="fill" className="mt-0.5 shrink-0" />
          <span>
            Account found{result.nickname ? `: ${result.nickname}` : ""}. Top-ups for this account&apos;s region
            aren&apos;t available on {storeName}.
          </span>
        </p>
      );
    case "not_found":
      return (
        <p className={`${box} flex items-start gap-2 bg-surface-container text-on-surface-variant`}>
          <XCircle size={18} weight="fill" className="mt-0.5 shrink-0" />
          <span>No account found for this ID. Check it and try again.</span>
        </p>
      );
    case "busy":
      return (
        <p className={`${box} flex items-start gap-2 bg-surface-container text-on-surface-variant`}>
          <Hourglass size={18} weight="fill" className="mt-0.5 shrink-0" />
          <span>The checker is busy right now. Please try again in a few minutes.</span>
        </p>
      );
  }
}

/**
 * `/check-id`'s family picker: the same card as the home page's
 * ProductCard (16:10 image, name), with "Check ID" where the price sits.
 */
export function FamilyCards({ families }: { families: PlayerCheckFamily[] }) {
  return (
    <nav aria-label="Choose a game" className="flex flex-wrap justify-center gap-4">
      {families.map((f) => (
        <Link
          key={f.slug}
          href={`/check-id/${f.slug}`}
          className="group flex w-[calc(50%-0.5rem)] flex-col lg:w-[calc(25%-0.75rem)] overflow-hidden rounded-lg border-2 border-ink bg-surface-container-lowest neo neo-hover transition-all"
        >
          <div className="relative aspect-16/10">
            {f.image_url ? (
              <Image src={f.image_url} alt={f.name} fill sizes="(min-width: 1024px) 25vw, 50vw" className="border-b-2 border-ink object-cover" />
            ) : (
              <PlaceholderTile label={f.name} />
            )}
          </div>
          <div className="flex flex-col gap-1.5 p-3.5">
            <span className="font-display text-[15px] font-bold leading-tight">{f.name}</span>
            <div className="mt-2 flex items-center justify-end gap-2 border-t border-dashed border-ink/40 pt-2 sm:justify-between">
              <span className="hidden text-[11px] text-on-surface-variant sm:inline">Free ID check</span>
              <span className="inline-flex items-center gap-1 whitespace-nowrap font-display text-[13px] font-bold uppercase text-primary-on-surface">
                Check ID <ArrowRight size={14} weight="bold" />
              </span>
            </div>
          </div>
        </Link>
      ))}
    </nav>
  );
}

/** The family page's compact switcher, so the form stays near the top. */
export function FamilyTiles({ families, activeSlug }: { families: PlayerCheckFamily[]; activeSlug?: string }) {
  return (
    <nav aria-label="Choose a game" className="grid grid-cols-3 gap-2 sm:grid-cols-5">
      {families.map((f) => (
        <Link
          key={f.slug}
          href={`/check-id/${f.slug}`}
          aria-current={f.slug === activeSlug ? "page" : undefined}
          className={`flex min-h-11 flex-col items-center gap-1.5 rounded-md border-2 border-ink p-2 text-center transition-all neo-hover ${
            f.slug === activeSlug ? "bg-primary-fixed" : "bg-surface-container-lowest hover:bg-surface-container-low"
          }`}
        >
          <span className="relative flex h-11 w-11 items-center justify-center overflow-hidden rounded-full border-2 border-ink bg-surface-container">
            {f.image_url ? (
              <Image src={f.image_url} alt="" fill className="object-cover" sizes="44px" />
            ) : (
              <span className="font-display text-sm font-bold">{f.name.charAt(0)}</span>
            )}
          </span>
          <span className="line-clamp-2 font-display text-[11px] font-bold leading-tight">{f.name}</span>
        </Link>
      ))}
    </nav>
  );
}
