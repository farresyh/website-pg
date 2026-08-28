"use client";

import React, { useState } from "react";
import { Button } from "@/components/ui/button";
import type { Game } from "@/lib/games";
import type { PlayerRegionMapping, PlayerValidatorProfile, TestValidatorResult } from "@/lib/player-validators";

const inputClasses =
  "h-9 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white/90";

function MappingChip({
  mapping,
  games,
  onUpdate,
  onDelete,
}: {
  mapping: PlayerRegionMapping;
  games: Game[];
  onUpdate: (countryName: string, gameId: number) => Promise<void>;
  onDelete: () => Promise<void>;
}) {
  const [editing, setEditing] = useState(false);
  const [countryName, setCountryName] = useState(mapping.country_name);
  const [gameId, setGameId] = useState(mapping.game_id);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);

  if (!editing) {
    return (
      <button
        onClick={() => setEditing(true)}
        className="rounded-lg bg-brand-50 px-3 py-1.5 text-sm text-brand-700 hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-400 dark:hover:bg-brand-500/20"
        title={mapping.country_name}
      >
        <span className="font-semibold">{mapping.country_code}</span>
        <span className="mx-1.5 text-brand-400">→</span>
        {mapping.game.slug}
      </button>
    );
  }

  async function handleSave() {
    setSaving(true);
    try {
      await onUpdate(countryName, gameId);
      setEditing(false);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    setDeleting(true);
    try {
      await onDelete();
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="col-span-full flex flex-wrap items-end gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-white/5">
      <span className="flex h-9 items-center px-1 text-sm font-semibold text-gray-500 dark:text-gray-400">
        {mapping.country_code}
      </span>
      <input
        value={countryName}
        onChange={(e) => setCountryName(e.target.value)}
        className={`${inputClasses} max-w-[10rem]`}
      />
      <select value={gameId} onChange={(e) => setGameId(Number(e.target.value))} className={`${inputClasses} max-w-[16rem]`}>
        {games.map((g) => (
          <option key={g.id} value={g.id}>
            {g.name} ({g.slug})
          </option>
        ))}
      </select>
      <Button size="small" disabled={saving} onClick={handleSave}>
        {saving ? "…" : "Save"}
      </Button>
      <Button size="small" variant="outlined" onClick={() => setEditing(false)}>
        Cancel
      </Button>
      <Button size="small" severity="danger" disabled={deleting} onClick={handleDelete}>
        {deleting ? "…" : "Delete"}
      </Button>
    </div>
  );
}

function AddMappingRow({
  games,
  onCreate,
}: {
  games: Game[];
  onCreate: (countryCode: string, countryName: string, gameId: number) => Promise<void>;
}) {
  const [open, setOpen] = useState(false);
  const [countryCode, setCountryCode] = useState("");
  const [countryName, setCountryName] = useState("");
  const [gameId, setGameId] = useState<number | "">("");
  const [saving, setSaving] = useState(false);

  if (!open) {
    return (
      <button
        onClick={() => setOpen(true)}
        className="rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-sm text-gray-500 hover:border-brand-400 hover:text-brand-500 dark:border-gray-600 dark:text-gray-400"
      >
        + Add Country
      </button>
    );
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (countryCode.trim().length !== 2 || !countryName.trim() || gameId === "") return;

    setSaving(true);
    try {
      await onCreate(countryCode.trim(), countryName.trim(), Number(gameId));
      setCountryCode("");
      setCountryName("");
      setGameId("");
      setOpen(false);
    } finally {
      setSaving(false);
    }
  }

  return (
    <form
      onSubmit={handleSubmit}
      className="col-span-full flex flex-wrap items-end gap-2 rounded-lg border border-dashed border-gray-300 p-3 dark:border-gray-600"
    >
      <input
        placeholder="MY"
        maxLength={2}
        value={countryCode}
        onChange={(e) => setCountryCode(e.target.value.toUpperCase())}
        className={`${inputClasses} max-w-[5rem] uppercase`}
      />
      <input
        placeholder="Malaysia"
        value={countryName}
        onChange={(e) => setCountryName(e.target.value)}
        className={`${inputClasses} max-w-[10rem]`}
      />
      <select
        value={gameId}
        onChange={(e) => setGameId(e.target.value ? Number(e.target.value) : "")}
        className={`${inputClasses} max-w-[16rem]`}
      >
        <option value="">Select a game…</option>
        {games.map((g) => (
          <option key={g.id} value={g.id}>
            {g.name} ({g.slug})
          </option>
        ))}
      </select>
      <Button type="submit" size="small" disabled={saving}>
        {saving ? "Adding…" : "Add"}
      </Button>
      <Button type="button" size="small" variant="outlined" onClick={() => setOpen(false)}>
        Cancel
      </Button>
    </form>
  );
}

function TestPanel({
  onTest,
}: {
  onTest: (playerId: string, serverId: string) => Promise<TestValidatorResult>;
}) {
  const [open, setOpen] = useState(false);
  const [playerId, setPlayerId] = useState("");
  const [serverId, setServerId] = useState("");
  const [running, setRunning] = useState(false);
  const [outcome, setOutcome] = useState<TestValidatorResult | null>(null);

  async function handleRun() {
    if (!playerId.trim()) return;
    setRunning(true);
    try {
      setOutcome(await onTest(playerId.trim(), serverId.trim()));
    } finally {
      setRunning(false);
    }
  }

  return (
    <div className="mt-3">
      <button
        onClick={() => setOpen((o) => !o)}
        className="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400"
      >
        {open ? "Hide test" : "Test this validator"}
      </button>

      {open && (
        <div className="mt-2 space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-white/5">
          <p className="text-xs text-gray-500 dark:text-gray-400">
            Runs a real validate() call against whatever this key resolves to on the backend — proves the validator
            is genuinely plugged in, not just that this row exists.
          </p>
          <div className="flex flex-wrap items-end gap-2">
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
            <Button size="small" disabled={running || !playerId.trim()} onClick={handleRun}>
              {running ? "Running…" : "Run Test"}
            </Button>
          </div>
          {outcome && (
            <pre
              className={`overflow-x-auto rounded-lg p-3 text-xs ${
                outcome.success
                  ? "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400"
                  : "bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400"
              }`}
            >
              {JSON.stringify(outcome.result ?? { error: outcome.error }, null, 2)}
            </pre>
          )}
        </div>
      )}
    </div>
  );
}

export default function ValidatorCard({
  validator,
  games,
  onRename,
  onDelete,
  onTest,
  onCreateMapping,
  onUpdateMapping,
  onDeleteMapping,
}: {
  validator: PlayerValidatorProfile;
  games: Game[];
  onRename: (name: string) => Promise<void>;
  onDelete: () => Promise<void>;
  onTest: (playerId: string, serverId: string) => Promise<TestValidatorResult>;
  onCreateMapping: (countryCode: string, countryName: string, gameId: number) => Promise<void>;
  onUpdateMapping: (mapping: PlayerRegionMapping, countryName: string, gameId: number) => Promise<void>;
  onDeleteMapping: (mapping: PlayerRegionMapping) => Promise<void>;
}) {
  const [renaming, setRenaming] = useState(false);
  const [name, setName] = useState(validator.name);
  const [savingName, setSavingName] = useState(false);
  const [deleting, setDeleting] = useState(false);

  async function handleSaveName() {
    setSavingName(true);
    try {
      await onRename(name);
      setRenaming(false);
    } finally {
      setSavingName(false);
    }
  }

  async function handleDelete() {
    setDeleting(true);
    try {
      await onDelete();
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
      <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          {renaming ? (
            <>
              <input value={name} onChange={(e) => setName(e.target.value)} className={`${inputClasses} max-w-[16rem]`} />
              <Button size="small" disabled={savingName} onClick={handleSaveName}>
                {savingName ? "…" : "Save"}
              </Button>
              <Button size="small" variant="outlined" onClick={() => setRenaming(false)}>
                Cancel
              </Button>
            </>
          ) : (
            <>
              <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">{validator.name}</h3>
              <span className="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-500 dark:bg-white/10 dark:text-gray-400">
                {validator.key}
              </span>
            </>
          )}
        </div>
        {!renaming && (
          <div className="flex items-center gap-3 text-sm">
            <button onClick={() => setRenaming(true)} className="text-brand-500 hover:text-brand-600 dark:text-brand-400">
              Edit
            </button>
            <button
              onClick={handleDelete}
              disabled={deleting}
              className="text-error-500 hover:text-error-600 disabled:opacity-50 dark:text-error-400"
            >
              {deleting ? "…" : "Delete"}
            </button>
          </div>
        )}
      </div>

      {validator.last_test_result && (
        <p className="mb-3 text-xs text-gray-400">
          Last tested {validator.last_tested_at ? new Date(validator.last_tested_at).toLocaleString() : ""} —{" "}
          {validator.last_test_result}
        </p>
      )}

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
        {(validator.mappings ?? []).map((mapping) => (
          <MappingChip
            key={`${mapping.id}-${mapping.country_name}-${mapping.game_id}`}
            mapping={mapping}
            games={games}
            onUpdate={(countryName, gameId) => onUpdateMapping(mapping, countryName, gameId)}
            onDelete={() => onDeleteMapping(mapping)}
          />
        ))}
        <AddMappingRow games={games} onCreate={onCreateMapping} />
      </div>

      <TestPanel onTest={onTest} />
    </div>
  );
}
