"use client";

import { useMemo, useState } from "react";
import type { ResellerPriceList } from "@/lib/reseller-price-list";

/**
 * ADR-091: client-side search/filter over the full list — no page
 * reload, no per-keystroke fetch. The list is small enough (a few
 * hundred reseller-eligible packages at most) that filtering it in the
 * browser is simpler and faster than a debounced server round-trip.
 */
export default function ResellerPriceListTable({ list }: { list: ResellerPriceList }) {
  const [query, setQuery] = useState("");

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (q === "") return list.rows;
    return list.rows.filter((row) => row.gameName.toLowerCase().includes(q));
  }, [list.rows, query]);

  return (
    <div>
      <label className="block">
        <span className="sr-only">Search a game</span>
        <input
          type="text"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search a game…"
          className="w-full rounded-lg border-2 border-ink bg-surface-container-lowest px-4 py-3 text-sm text-on-surface outline-none placeholder:text-on-surface-variant focus:ring-2 focus:ring-primary"
        />
      </label>

      <div className="mt-5 overflow-x-auto rounded-lg border-2 border-ink bg-surface-container-lowest neo">
        <table className="w-full min-w-[480px] text-sm">
          <thead>
            <tr className="border-b-2 border-ink text-left">
              <th className="px-4 py-3 font-display text-[12.5px] font-bold uppercase tracking-wide text-on-surface-variant">
                Game / Package
              </th>
              {list.tiers.map((tier) => (
                <th
                  key={tier.id}
                  className="px-4 py-3 text-right font-display text-[12.5px] font-bold uppercase tracking-wide text-primary"
                >
                  {tier.name}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-ink/10">
            {filtered.map((row, i) => (
              <tr key={`${row.gameName}-${row.packageName}-${i}`}>
                <td className="px-4 py-3">
                  <div className="font-medium text-on-surface">{row.gameName}</div>
                  <div className="text-xs text-on-surface-variant">{row.packageName}</div>
                </td>
                {list.tiers.map((tier) => (
                  <td key={tier.id} className="px-4 py-3 text-right font-mono tabular-nums text-on-surface">
                    RM{(row.pricesRmByTierId[tier.id] ?? 0).toFixed(2)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
        {filtered.length === 0 && (
          <p className="px-4 py-8 text-center text-sm text-on-surface-variant">No game found for &quot;{query}&quot;.</p>
        )}
      </div>
      {/* The table's own rounded border sits on the scrollport, not the
          full scrollable width — on a narrow viewport it can clip a tier
          column flush with no visual cut to signal more exists. An
          explicit hint below the table is the one affordance that's
          foolproof (no fragile gradient-over-a-themed-background trick)
          when there's more than 2 tiers to scroll to. */}
      {list.tiers.length > 2 && (
        <p className="mt-2 text-center text-xs text-on-surface-variant sm:hidden">
          ← Swipe to see all {list.tiers.length} tiers →
        </p>
      )}
    </div>
  );
}
