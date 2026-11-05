"use client";

import React, { useEffect, useMemo, useRef, useState } from "react";
import { Input } from "@/components/ui/input";
import { Tag } from "@/components/ui/tag";
import { listGamevionGames, type GamevionGame } from "@/lib/suppliers";

interface GamevionGamePickerProps {
  value: string[];
  onChange: (codes: string[]) => void;
}

const checkboxClass = "size-3.5 shrink-0 rounded border-border-control accent-[var(--color-ink)]";

function FamilyCheckbox({ checked, indeterminate, onChange, label }: { checked: boolean; indeterminate: boolean; onChange: () => void; label: string }) {
  const ref = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (ref.current) ref.current.indeterminate = indeterminate;
  }, [indeterminate]);

  return <input ref={ref} type="checkbox" className={checkboxClass} checked={checked} onChange={onChange} aria-label={label} />;
}

/**
 * ADR-137 2026-11-05 decision 1: Gamevion's `gametopup` games, grouped by its
 * own family, live from Gamevion (one call). Only the ticked codes are
 * synced. An unticked game in a family that already has a ticked one is
 * marked "New", so a region Gamevion adds later is easy to spot.
 */
export default function GamevionGamePicker({ value, onChange }: GamevionGamePickerProps) {
  const [games, setGames] = useState<GamevionGame[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [query, setQuery] = useState("");

  useEffect(() => {
    listGamevionGames()
      .then(setGames)
      .catch((err: unknown) => setError(err instanceof Error ? err.message : "Could not load Gamevion's games."));
  }, []);

  const chosen = useMemo(() => new Set(value), [value]);
  // Families with a saved game list first, by the list the form opened with,
  // so a family never jumps away from the cursor when it is ticked.
  const [openedWith] = useState(() => new Set(value));

  const families = useMemo(() => {
    const needle = query.trim().toLowerCase();
    const byFamily = new Map<string, GamevionGame[]>();
    for (const game of games ?? []) {
      if (needle && !`${game.family} ${game.name} ${game.code}`.toLowerCase().includes(needle)) continue;
      byFamily.set(game.family, [...(byFamily.get(game.family) ?? []), game]);
    }
    return [...byFamily.entries()]
      .map(([family, list]) => ({ family, list, ticked: list.filter((g) => chosen.has(g.code)).length, saved: list.some((g) => openedWith.has(g.code)) }))
      .sort((a, b) => Number(b.saved) - Number(a.saved) || a.family.localeCompare(b.family));
  }, [games, query, chosen, openedWith]);

  // A chosen code Gamevion no longer offers: shown so it can be cleared.
  const gone = useMemo(() => (games ? value.filter((code) => !games.some((g) => g.code === code)) : []), [games, value]);

  function toggle(codes: string[], on: boolean) {
    const next = new Set(value);
    for (const code of codes) {
      if (on) next.add(code);
      else next.delete(code);
    }
    onChange([...next]);
  }

  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between gap-2">
        <span className="text-theme-sm font-medium text-ink">Games to sync</span>
        <span className="text-theme-xs text-ink-muted">{value.length} chosen</span>
      </div>
      <p className="mb-2 text-theme-xs text-ink-subtle">
        Only ticked games are synced (about 2 s each). Their products appear in Product Manager and sell only once promoted. Games that need a character name are skipped.
      </p>

      {error && <p className="rounded-md bg-danger-surface px-3 py-2 text-theme-sm text-danger-ink">{error}</p>}
      {!error && games === null && <p className="text-theme-sm text-ink-muted">Loading Gamevion&apos;s games…</p>}

      {games !== null && (
        <>
          <Input placeholder="Search game, region or code" value={query} onChange={(e) => setQuery(e.target.value)} />

          <div className="mt-2 max-h-80 overflow-y-auto rounded-md border border-border">
            {gone.length > 0 && (
              <div className="border-b border-border bg-warning-surface px-3 py-2">
                <p className="mb-1 text-theme-xs font-medium text-warning-ink">No longer offered by Gamevion</p>
                {gone.map((code) => (
                  <label key={code} className="flex items-center gap-2 py-0.5 text-theme-sm text-ink">
                    <input type="checkbox" className={checkboxClass} checked onChange={() => toggle([code], false)} />
                    <span className="font-mono text-theme-xs">{code}</span>
                  </label>
                ))}
              </div>
            )}

            {families.length === 0 && <p className="px-3 py-2 text-theme-sm text-ink-muted">No game matches.</p>}

            {families.map(({ family, list, ticked }) => (
              <details key={family} open={ticked > 0 || query.trim() !== ""} className="border-b border-border last:border-b-0">
                <summary className="flex cursor-pointer items-center gap-2 px-3 py-2 text-theme-sm text-ink hover:bg-neutral-surface">
                  <FamilyCheckbox
                    checked={ticked === list.length}
                    indeterminate={ticked > 0 && ticked < list.length}
                    onChange={() => toggle(list.map((g) => g.code), ticked < list.length)}
                    label={`All of ${family}`}
                  />
                  <span className="font-medium">{family}</span>
                  <span className="ml-auto text-theme-xs text-ink-muted">
                    {ticked}/{list.length}
                  </span>
                </summary>
                <div className="pb-2 pl-8 pr-3">
                  {list.map((game) => (
                    <label key={game.code} className="flex items-center gap-2 py-0.5 text-theme-sm text-ink">
                      <input type="checkbox" className={checkboxClass} checked={chosen.has(game.code)} onChange={(e) => toggle([game.code], e.target.checked)} />
                      <span>{game.name}</span>
                      <span className="font-mono text-theme-xs text-ink-subtle">{game.code}</span>
                      {ticked > 0 && !chosen.has(game.code) && <Tag severity="info">New</Tag>}
                    </label>
                  ))}
                </div>
              </details>
            ))}
          </div>
        </>
      )}
    </div>
  );
}
