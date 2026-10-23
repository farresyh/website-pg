"use client";

import type { JokiRank, JokiTier } from "@/lib/joki";

export const JOKI_INPUT =
  "rounded-lg border border-gray-200 bg-white px-3 py-2 text-theme-sm text-gray-800 shadow-theme-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 disabled:opacity-60 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90";

const ROMAN = ["", "I", "II", "III", "IV", "V", "VI", "VII", "VIII"];

type PickerTier = Pick<JokiTier, "id" | "name" | "kind" | "divisions" | "stars_per_division" | "mythic_from_stars">;

/**
 * ADR-126 decision 3: a rank on the panel's model. Divisional tiers pick a
 * division (V … I) and 0 … n−1 stars; a Mythic tier takes its star count
 * on the Mythic scale. `allowGrading` lets the lowest Mythic tier be
 * "Grading" (stars null), the start of a route. The backend re-checks it.
 */
export function RankPicker({ tiers, value, onChange, allowGrading = false, idPrefix }: {
  tiers: PickerTier[];
  value: JokiRank;
  onChange: (rank: JokiRank) => void;
  allowGrading?: boolean;
  idPrefix: string;
}) {
  const tier = tiers.find((t) => t.id === value.tier_id);
  const lowestMythic = tiers.find((t) => t.kind === "mythic");

  function pickTier(id: number) {
    const next = tiers.find((t) => t.id === id);
    if (!next) return;
    onChange(next.kind === "divisional"
      ? { tier_id: id, division: next.divisions, stars: 0 }
      : { tier_id: id, division: null, stars: next.mythic_from_stars ?? 0 });
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <select id={`${idPrefix}-tier`} aria-label="Tier" className={JOKI_INPUT} value={value.tier_id} onChange={(e) => pickTier(Number(e.target.value))}>
        {tiers.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
      </select>
      {tier?.kind === "divisional" && (
        <>
          <select aria-label="Division" className={JOKI_INPUT} value={value.division ?? ""} onChange={(e) => onChange({ ...value, division: Number(e.target.value) })}>
            {Array.from({ length: tier.divisions ?? 0 }, (_, i) => (tier.divisions ?? 0) - i).map((d) => <option key={d} value={d}>{ROMAN[d] ?? d}</option>)}
          </select>
          <select aria-label="Stars" className={JOKI_INPUT} value={value.stars ?? 0} onChange={(e) => onChange({ ...value, stars: Number(e.target.value) })}>
            {Array.from({ length: tier.stars_per_division ?? 0 }, (_, s) => <option key={s} value={s}>{s}★</option>)}
          </select>
        </>
      )}
      {tier?.kind === "mythic" && (
        <>
          {allowGrading && tier.id === lowestMythic?.id && (
            <label className="flex items-center gap-1 text-theme-xs text-ink-muted">
              <input type="checkbox" checked={value.stars === null} onChange={(e) => onChange({ ...value, stars: e.target.checked ? null : (tier.mythic_from_stars ?? 0) })} />
              Grading
            </label>
          )}
          {value.stars !== null && (
            <input
              type="number"
              aria-label="Mythic stars"
              min={0}
              className={`${JOKI_INPUT} w-24`}
              value={value.stars}
              onChange={(e) => onChange({ ...value, stars: Number(e.target.value) })}
            />
          )}
        </>
      )}
    </div>
  );
}
