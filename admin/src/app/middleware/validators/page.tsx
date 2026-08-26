"use client";

/**
 * MUI-5 — "Validators: CRUD interface for region-based game routing
 * rules". Restructured 2026-07-25 per founder feedback: a validator is
 * a first-class, admin-created entity (not a loose string tag), region
 * mappings are nested under the validator they belong to, and a "Test"
 * action proves a validator is genuinely plugged in on the backend —
 * not just that this row exists. See
 * backend/app/Http/Controllers/Middleware/PlayerValidatorProfileController.php.
 */

import React, { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Button from "@/components/ui/button/Button";
import CreateValidatorModal from "@/components/middleware/CreateValidatorModal";
import ValidatorCard from "@/components/middleware/ValidatorCard";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  type PlayerRegionMapping,
  type PlayerValidatorProfile,
  type AvailableValidatorKey,
  listPlayerValidatorProfiles,
  listAvailableValidatorKeys,
  createPlayerValidatorProfile,
  updatePlayerValidatorProfile,
  deletePlayerValidatorProfile,
  testPlayerValidatorProfile,
  createPlayerRegionMapping,
  updatePlayerRegionMapping,
  deletePlayerRegionMapping,
} from "@/lib/player-validators";
import { type Game, listGames } from "@/lib/games";

export default function ValidatorsPage() {
  const router = useRouter();
  const session = useClientSession();
  const [validators, setValidators] = useState<PlayerValidatorProfile[] | null>(null);
  const [availableKeys, setAvailableKeys] = useState<AvailableValidatorKey[]>([]);
  const [games, setGames] = useState<Game[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [createOpen, setCreateOpen] = useState(false);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function load(token: string) {
    return Promise.all([listPlayerValidatorProfiles(token), listAvailableValidatorKeys(token), listGames(token)]).then(
      ([validatorsResult, keysResult, gamesResult]) => {
        setValidators(validatorsResult);
        setAvailableKeys(keysResult);
        setGames(gamesResult);
      },
    );
  }

  useEffect(() => {
    if (!session) return;
    load(session.token).catch((err: unknown) => {
      setError(err instanceof ApiError ? err.message : "Could not load validators.");
    });
     
  }, [session]);

  async function handleRefresh() {
    if (!session) return;
    setError(null);
    try {
      await load(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not refresh validators.");
    }
  }

  async function handleCreate(values: { name: string; key: string }) {
    if (!session) return;
    const created = await createPlayerValidatorProfile(session.token, values);
    setValidators((prev) => [...(prev ?? []), created]);
    setCreateOpen(false);
  }

  async function handleRename(validator: PlayerValidatorProfile, name: string) {
    if (!session) return;
    setError(null);
    try {
      const updated = await updatePlayerValidatorProfile(session.token, validator.id, { name });
      setValidators((prev) => prev?.map((v) => (v.id === validator.id ? updated : v)) ?? null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not rename validator.");
    }
  }

  async function handleDeleteValidator(validator: PlayerValidatorProfile) {
    if (!session) return;
    setError(null);
    try {
      await deletePlayerValidatorProfile(session.token, validator.id);
      setValidators((prev) => prev?.filter((v) => v.id !== validator.id) ?? null);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete validator.");
    }
  }

  async function handleTest(validator: PlayerValidatorProfile, playerId: string, serverId: string) {
    if (!session) throw new Error("No session");
    const outcome = await testPlayerValidatorProfile(session.token, validator.id, {
      player_id: playerId,
      server_id: serverId || undefined,
    });
    setValidators((prev) => prev?.map((v) => (v.id === validator.id ? outcome.validator : v)) ?? null);
    return outcome;
  }

  async function handleCreateMapping(validator: PlayerValidatorProfile, countryCode: string, countryName: string, gameId: number) {
    if (!session) return;
    setError(null);
    try {
      const created = await createPlayerRegionMapping(session.token, validator.id, {
        country_code: countryCode,
        country_name: countryName,
        game_id: gameId,
      });
      setValidators(
        (prev) => prev?.map((v) => (v.id === validator.id ? { ...v, mappings: [...v.mappings, created] } : v)) ?? null,
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not add country mapping.");
    }
  }

  async function handleUpdateMapping(
    validator: PlayerValidatorProfile,
    mapping: PlayerRegionMapping,
    countryName: string,
    gameId: number,
  ) {
    if (!session) return;
    setError(null);
    try {
      const updated = await updatePlayerRegionMapping(session.token, validator.id, mapping.id, {
        country_name: countryName,
        game_id: gameId,
      });
      setValidators(
        (prev) =>
          prev?.map((v) =>
            v.id === validator.id ? { ...v, mappings: v.mappings.map((m) => (m.id === mapping.id ? updated : m)) } : v,
          ) ?? null,
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update country mapping.");
    }
  }

  async function handleDeleteMapping(validator: PlayerValidatorProfile, mapping: PlayerRegionMapping) {
    if (!session) return;
    setError(null);
    try {
      await deletePlayerRegionMapping(session.token, validator.id, mapping.id);
      setValidators(
        (prev) =>
          prev?.map((v) => (v.id === validator.id ? { ...v, mappings: v.mappings.filter((m) => m.id !== mapping.id) } : v)) ??
          null,
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete country mapping.");
    }
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Validators</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          MUI-5 — a validator&apos;s <code>key</code> only ever comes from the finite set with a real backend
          implementation, so it can never point at nothing. Use &quot;Test this validator&quot; on any card to prove
          it&apos;s genuinely working, not just configured.
        </p>
      </div>

      <div className="mb-6 flex gap-2">
        <Button onClick={() => setCreateOpen(true)}>+ Create Validator</Button>
        <Button variant="outline" onClick={handleRefresh}>
          Refresh
        </Button>
      </div>

      <CreateValidatorModal
        isOpen={createOpen}
        onClose={() => setCreateOpen(false)}
        onSubmit={handleCreate}
        availableKeys={availableKeys}
        usedKeys={validators?.map((v) => v.key) ?? []}
      />

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="space-y-4">
        {validators?.map((validator) => (
          <ValidatorCard
            key={validator.id}
            validator={validator}
            games={games}
            onRename={(name) => handleRename(validator, name)}
            onDelete={() => handleDeleteValidator(validator)}
            onTest={(playerId, serverId) => handleTest(validator, playerId, serverId)}
            onCreateMapping={(countryCode, countryName, gameId) =>
              handleCreateMapping(validator, countryCode, countryName, gameId)
            }
            onUpdateMapping={(mapping, countryName, gameId) => handleUpdateMapping(validator, mapping, countryName, gameId)}
            onDeleteMapping={(mapping) => handleDeleteMapping(validator, mapping)}
          />
        ))}
      </div>

      {validators?.length === 0 && (
        <p className="rounded-2xl border border-gray-200 p-6 text-center text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
          No validators yet — create one above.
        </p>
      )}
      {validators === null && !error && (
        <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
      )}
    </div>
  );
}
