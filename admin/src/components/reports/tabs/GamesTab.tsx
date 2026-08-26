"use client";

import { useEffect, useState } from "react";
import { type ReportFilters, type ReportGameRow, getGameBreakdown } from "@/lib/reports";
import { GameBreakdownTable } from "../GameBreakdownTable";

export function GamesTab({ token, filters }: { token: string; filters: ReportFilters }) {
  const [games, setGames] = useState<ReportGameRow[] | null>(null);

  useEffect(() => {
    getGameBreakdown(token, filters)
      .then((res) => setGames(res.games))
      .catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, filters.year, filters.month, filters.resellerId]);

  return (
    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
      <h2 className="px-4 pt-4 text-sm font-semibold text-gray-800 dark:text-white/90">Games Breakdown</h2>
      {games ? <GameBreakdownTable rows={games} /> : <p className="p-6 text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
    </div>
  );
}
