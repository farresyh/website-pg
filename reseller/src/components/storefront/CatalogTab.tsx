"use client";

import { useEffect, useMemo, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  getStorefrontGames,
  setStorefrontGameVisibility,
  type StorefrontGamesResponse,
} from "@/lib/portal";
import { Panel, ErrorNote, EmptyRow } from "@/components/ui";
import { Toggle, inputClass, InactiveNotice, TabLoading } from "./shared";

export default function CatalogTab() {
  const [data, setData] = useState<StorefrontGamesResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [busyId, setBusyId] = useState<number | null>(null);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;
    let cancelled = false;
    getStorefrontGames(session.token)
      .then((result) => !cancelled && setData(result))
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : "Could not load your catalog.");
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const games = useMemo(() => data?.games ?? [], [data]);
  const writable = data?.writable ?? false;
  const visibleCount = useMemo(() => games.filter((g) => g.is_visible).length, [games]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return q ? games.filter((g) => g.name.toLowerCase().includes(q)) : games;
  }, [games, search]);

  async function toggle(gameId: number, next: boolean) {
    const session = getClientSession();
    if (!session) return;
    setError(null);
    setBusyId(gameId);
    try {
      const result = await setStorefrontGameVisibility(session.token, gameId, next);
      setData(result);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update that game.");
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="space-y-4">
      {error && <ErrorNote message={error} />}
      {data && !writable && <InactiveNotice />}
      {!data && !error && <TabLoading />}

      {data && (
        <>
          <p className="text-theme-sm text-gray-500 dark:text-gray-400">
            Every game is shown on your storefront by default. Turn one off to hide it. At least one game must
            stay visible.
          </p>
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search games…"
            className={`${inputClass} max-w-xs`}
          />

          <Panel>
            <ul className="divide-y divide-gray-100 dark:divide-gray-800">
              {filtered.map((game) => {
                const lastVisible = game.is_visible && visibleCount <= 1;
                return (
                  <li key={game.id} className="flex items-center gap-4 px-5 py-3">
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90">
                        {game.name}
                      </p>
                      {game.category && (
                        <p className="text-theme-xs text-gray-500 dark:text-gray-400">{game.category}</p>
                      )}
                    </div>
                    <span
                      title={lastVisible ? "At least one game must stay visible on your storefront." : undefined}
                    >
                      <Toggle
                        checked={game.is_visible}
                        disabled={!writable || busyId === game.id || lastVisible}
                        onChange={(next) => toggle(game.id, next)}
                      />
                    </span>
                  </li>
                );
              })}
            </ul>
            {filtered.length === 0 && <EmptyRow>No games match “{search}”.</EmptyRow>}
          </Panel>
        </>
      )}
    </div>
  );
}
