"use client";

/**
 * MUI-7 — "Validate Player: game selector, player ID input, validation
 * result (shows 'not supported for this game' state where applicable)".
 * Grilled 2026-08-28 (ADR-052): merged onto this page rather than a
 * standalone screen, reusing the same public, no-auth endpoint the
 * storefront wizard and ORD-7 resend flow already call
 * (`validatePlayerForResend`, `admin/src/lib/orders.ts`) — this
 * simulates the exact game/region resolution a real customer hits,
 * distinct from the per-validator raw connectivity test above (which
 * calls a validator's provider directly, bypassing game/region
 * mapping entirely).
 */

import React, { useState } from "react";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api-client";
import { validatePlayerForResend, type ValidatePlayerForResendResult } from "@/lib/orders";
import type { Game } from "@/lib/games";

const inputClasses =
  "h-9 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90";

const STATUS_LABEL: Record<ValidatePlayerForResendResult["status"], string> = {
  valid: "Valid",
  invalid: "Invalid Player ID",
  region_unknown: "Region Unknown",
  wrong_region: "Wrong Region",
};

const STATUS_CLASSES: Record<ValidatePlayerForResendResult["status"], string> = {
  valid: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400",
  invalid: "bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400",
  region_unknown: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
  wrong_region: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
};

export default function ValidateByGamePanel({ games }: { games: Game[] }) {
  const [gameId, setGameId] = useState<number | "">("");
  const [playerId, setPlayerId] = useState("");
  const [serverId, setServerId] = useState("");
  const [running, setRunning] = useState(false);
  const [result, setResult] = useState<ValidatePlayerForResendResult | null>(null);
  const [notSupported, setNotSupported] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const selectedGame = games.find((g) => g.id === gameId) ?? null;
  const staticallyUnsupported = selectedGame !== null && selectedGame.player_validator_enabled !== true;

  async function handleRun() {
    if (gameId === "" || !playerId.trim()) return;
    setRunning(true);
    setError(null);
    setResult(null);
    setNotSupported(false);
    try {
      const outcome = await validatePlayerForResend(gameId, playerId.trim(), serverId.trim() || undefined);
      setResult(outcome);
    } catch (err) {
      if (err instanceof ApiError && /not available for this game/i.test(err.message)) {
        setNotSupported(true);
      } else {
        setError(err instanceof ApiError ? err.message : "Could not run validation.");
      }
    } finally {
      setRunning(false);
    }
  }

  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Validate Player (by Game)</h3>
      <p className="mb-4 text-xs text-gray-500 dark:text-gray-400">
        Runs the same public, no-auth check the storefront wizard uses — resolves the game&apos;s validator and
        region mapping, exactly what a customer would see, rather than testing a validator key directly.
      </p>

      <div className="flex flex-wrap items-end gap-2">
        <select
          value={gameId}
          onChange={(e) => {
            setGameId(e.target.value ? Number(e.target.value) : "");
            setResult(null);
            setNotSupported(false);
            setError(null);
          }}
          className={`${inputClasses} max-w-[16rem]`}
        >
          <option value="">Select a game…</option>
          {games.map((g) => (
            <option key={g.id} value={g.id}>
              {g.name} ({g.slug})
            </option>
          ))}
        </select>
        <input
          placeholder="Player ID"
          value={playerId}
          onChange={(e) => setPlayerId(e.target.value)}
          className={`${inputClasses} max-w-[10rem]`}
        />
        <input
          placeholder="Server ID (optional)"
          value={serverId}
          onChange={(e) => setServerId(e.target.value)}
          className={`${inputClasses} max-w-[10rem]`}
        />
        <Button
          size="small"
          disabled={running || gameId === "" || !playerId.trim() || staticallyUnsupported}
          onClick={handleRun}
        >
          {running ? "Running…" : "Validate"}
        </Button>
      </div>

      {staticallyUnsupported && (
        <p className="mt-3 rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-400">
          Not supported for this game — player-ID validation isn&apos;t enabled on {selectedGame?.name}.
        </p>
      )}

      {notSupported && !staticallyUnsupported && (
        <p className="mt-3 rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-400">
          Not supported for this game.
        </p>
      )}

      {error && (
        <p className="mt-3 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {result && (
        <div className={`mt-3 rounded-lg p-3 text-sm ${STATUS_CLASSES[result.status]}`}>
          <p className="font-semibold">{STATUS_LABEL[result.status]}</p>
          {result.nickname && <p className="mt-1">Nickname: {result.nickname}</p>}
          {result.country_code && <p className="mt-1">Detected region: {result.country_code}</p>}
          {result.status === "wrong_region" && result.redirect_game && (
            <p className="mt-1">Belongs to: {result.redirect_game.name}</p>
          )}
        </div>
      )}
    </div>
  );
}
