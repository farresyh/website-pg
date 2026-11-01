"use client";

import Image from "next/image";
import { useDeferredValue, useMemo, useState } from "react";
import PriceRows from "@/components/price-list/PriceRows";
import type { ResellerPriceList, ResellerPriceListGroup } from "@/lib/reseller-price-list";

/** Searching starts at two characters: one letter matches nearly every row. */
const MIN_SEARCH = 2;

const swipeRow =
  "-mx-4 flex gap-2 overflow-x-auto px-4 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden scroll-px-4 snap-x snap-proximity";

/** "Mobile Legends · Malaysia"; the region alone when it already carries the family's name. */
function titleOf(group: ResellerPriceListGroup, region: string): string {
  return region.toLowerCase().includes(group.family.toLowerCase()) ? region : `${group.family} · ${region}`;
}

function GameArt({ group, size }: { group: ResellerPriceListGroup; size: number }) {
  return (
    <span
      className="relative flex shrink-0 items-center justify-center overflow-hidden rounded-lg border-2 border-ink bg-primary-fixed font-display font-bold text-on-primary-fixed"
      style={{ width: size, height: size }}
    >
      {group.imageUrl ? (
        <Image src={group.imageUrl} alt="" fill sizes={`${size}px`} className="object-cover" />
      ) : (
        group.family.charAt(0).toUpperCase()
      )}
    </span>
  );
}

/**
 * ADR-091 2026-11-01: browse the reseller price list by game, then region,
 * one region's table at a time (5 to 83 rows, not all 674). Everything is
 * already in the browser, so choosing and searching never touch the network;
 * search is deferred (`useDeferredValue`, no timer) so typing stays smooth on
 * a slow phone while the result list catches up.
 */
export default function ResellerPriceListBrowser({ list }: { list: ResellerPriceList }) {
  const [familyIndex, setFamilyIndex] = useState(0);
  const [regionIndex, setRegionIndex] = useState(0);
  const [query, setQuery] = useState("");
  const deferred = useDeferredValue(query.trim().toLowerCase());
  const searching = deferred.length >= MIN_SEARCH;

  const matches = useMemo(() => {
    if (!searching) return [];
    return list.groups.flatMap((group) =>
      group.games.flatMap((game) => {
        const packages = game.packages.filter((pkg) => `${game.name} ${pkg.name}`.toLowerCase().includes(deferred));
        return packages.length > 0 ? [{ key: `${group.family}-${game.name}`, name: game.name, packages }] : [];
      }),
    );
  }, [list.groups, deferred, searching]);

  const group = list.groups[familyIndex] ?? list.groups[0];
  const game = group?.games[regionIndex] ?? group?.games[0];

  return (
    <div>
      <label className="block">
        <span className="sr-only">Search a game or package</span>
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search a game or package…"
          className="w-full rounded-lg border-2 border-ink bg-surface-container-lowest px-4 py-3 text-sm text-on-surface outline-none placeholder:text-on-surface-variant focus:ring-2 focus:ring-primary"
        />
      </label>

      {!searching && group && game && (
        <>
          <p className="mb-2.5 mt-6 font-display text-xs font-bold uppercase tracking-wider text-on-surface-variant">1 · Choose a game</p>
          <div className={`${swipeRow} sm:mx-0 sm:grid sm:grid-cols-[repeat(auto-fill,minmax(88px,1fr))] sm:gap-2.5 sm:overflow-visible sm:px-0 sm:pb-0`}>
            {list.groups.map((g, i) => (
              <button
                key={g.family}
                type="button"
                aria-pressed={i === familyIndex}
                onClick={() => {
                  setFamilyIndex(i);
                  setRegionIndex(0);
                }}
                className="flex w-[84px] shrink-0 snap-start flex-col items-center gap-1.5 rounded-lg border-2 border-ink bg-surface-container-lowest px-1.5 pb-2 pt-2.5 transition-all aria-pressed:-translate-x-px aria-pressed:-translate-y-px aria-pressed:bg-primary aria-pressed:text-on-primary aria-pressed:neo sm:w-auto [@media(hover:hover)]:hover:not-aria-pressed:shadow-[4px_4px_0_0_var(--color-primary)]"
              >
                <GameArt group={g} size={40} />
                <span className="line-clamp-2 text-center font-display text-[11.5px] font-semibold leading-tight">{g.family}</span>
              </button>
            ))}
          </div>

          {group.games.length > 1 && (
            <>
              <p className="mb-2.5 mt-6 font-display text-xs font-bold uppercase tracking-wider text-on-surface-variant">2 · Choose a region</p>
              <div className={`${swipeRow} sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 sm:pb-0`}>
                {group.games.map((g, i) => (
                  <button
                    key={g.name}
                    type="button"
                    aria-pressed={i === regionIndex}
                    onClick={() => setRegionIndex(i)}
                    className="shrink-0 whitespace-nowrap rounded-full border-2 border-ink bg-surface-container-lowest px-3.5 py-1.5 font-display text-[13px] font-semibold aria-pressed:bg-ink aria-pressed:text-surface"
                  >
                    {g.region}
                    <span className="ml-1 font-sans text-[11px] font-medium opacity-70">{g.packages.length}</span>
                  </button>
                ))}
              </div>
            </>
          )}

          <div className="mb-3 mt-6 flex items-center gap-3">
            <GameArt group={group} size={40} />
            <div>
              <h2 className="font-display text-xl font-bold text-on-surface">{titleOf(group, game.region)}</h2>
              <p className="text-[12.5px] text-on-surface-variant">
                {game.packages.length} packages · {list.tiers.length} tiers
              </p>
            </div>
          </div>
          <div className="rounded-lg border-2 border-ink bg-surface-container-lowest neo">
            <PriceRows tiers={list.tiers} packages={game.packages} />
          </div>
        </>
      )}

      {searching && (
        <div className="mt-6 rounded-lg border-2 border-ink bg-surface-container-lowest neo">
          {matches.length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-on-surface-variant">No game or package found for &quot;{query.trim()}&quot;.</p>
          ) : (
            matches.map((match) => (
              <section key={match.key} className="border-t-2 border-ink first:border-t-0">
                <h2 className="px-4 pb-1 pt-4 font-display text-[13px] font-bold text-on-surface">
                  {match.name} <span className="font-sans text-[11.5px] font-medium text-on-surface-variant">· {match.packages.length} packages</span>
                </h2>
                <PriceRows tiers={list.tiers} packages={match.packages} />
              </section>
            ))
          )}
        </div>
      )}
    </div>
  );
}
