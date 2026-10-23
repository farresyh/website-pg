"use client";

import Image from "next/image";
import type { JokiRank, JokiTier } from "@/lib/joki";
import { idInputClass, idLabelClass } from "@/components/order/idFields";

const ROMAN = ["", "I", "II", "III", "IV", "V", "VI", "VII", "VIII"];

/**
 * ADR-126 decision 3: a rank on the panel's model — a tier tile, then a
 * division (V … I) and 0 … n−1 stars, or a Mythic star count. On the
 * current-rank side the lowest Mythic tier can be "Grading" (stars null),
 * for an account still in Mythic Grading. The backend checks every value.
 */
export default function JokiRankPicker({ id, label, tiers, value, onChange, allowGrading = false, maxMythicStars }: {
  id: string;
  label: string;
  tiers: JokiTier[];
  value: JokiRank | null;
  onChange: (rank: JokiRank) => void;
  allowGrading?: boolean;
  maxMythicStars: number;
}) {
  const tier = tiers.find((t) => t.id === value?.tier_id) ?? null;
  const lowestMythic = tiers.find((t) => t.kind === "mythic");

  function pickTier(next: JokiTier) {
    onChange(next.kind === "divisional"
      ? { tier_id: next.id, division: next.divisions, stars: 0 }
      : { tier_id: next.id, division: null, stars: next.id === lowestMythic?.id && allowGrading ? null : (next.mythic_from_stars ?? 0) });
  }

  return (
    <fieldset>
      <legend className={idLabelClass}>{label}</legend>
      <div className="grid grid-cols-3 gap-2 sm:grid-cols-5">
        {tiers.map((t) => {
          const selected = t.id === tier?.id;
          return (
            <button
              key={t.id}
              type="button"
              aria-pressed={selected}
              onClick={() => pickTier(t)}
              className={`flex min-h-11 flex-col items-center gap-1 rounded-md border-2 p-2 text-center transition-all ${
                selected ? "border-primary bg-primary-fixed neo-sm" : "border-ink bg-surface-container-lowest hover:bg-surface-container-low"
              }`}
            >
              {t.icon_url && <Image src={t.icon_url} alt="" width={36} height={36} className="h-9 w-9 object-contain" unoptimized />}
              <span className="font-display text-[11px] font-bold uppercase leading-tight">{t.name}</span>
            </button>
          );
        })}
      </div>

      {tier?.kind === "divisional" && value && (
        <div className="mt-3 flex flex-wrap gap-3">
          <label className="flex flex-col">
            <span className="mb-1 text-xs font-semibold text-on-surface-variant">Division</span>
            <select
              id={`${id}-division`}
              className={`${idInputClass} w-24`}
              value={value.division ?? ""}
              onChange={(e) => onChange({ ...value, division: Number(e.target.value) })}
            >
              {Array.from({ length: tier.divisions ?? 0 }, (_, i) => (tier.divisions ?? 0) - i).map((d) => (
                <option key={d} value={d}>{ROMAN[d] ?? d}</option>
              ))}
            </select>
          </label>
          <label className="flex flex-col">
            <span className="mb-1 text-xs font-semibold text-on-surface-variant">Stars</span>
            <select
              id={`${id}-stars`}
              className={`${idInputClass} w-24`}
              value={value.stars ?? 0}
              onChange={(e) => onChange({ ...value, stars: Number(e.target.value) })}
            >
              {Array.from({ length: tier.stars_per_division ?? 0 }, (_, s) => (
                <option key={s} value={s}>{s}★</option>
              ))}
            </select>
          </label>
        </div>
      )}

      {tier?.kind === "mythic" && value && (
        <div className="mt-3 flex flex-wrap items-end gap-3">
          {allowGrading && tier.id === lowestMythic?.id && (
            <label className="flex min-h-11 items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={value.stars === null}
                onChange={(e) => onChange({ ...value, stars: e.target.checked ? null : (tier.mythic_from_stars ?? 0) })}
                className="h-4 w-4"
              />
              Still in Mythic Grading
            </label>
          )}
          {value.stars !== null && (
            <label className="flex flex-col">
              <span className="mb-1 text-xs font-semibold text-on-surface-variant">Mythic stars</span>
              <input
                id={`${id}-mythic-stars`}
                type="number"
                inputMode="numeric"
                min={0}
                max={maxMythicStars}
                className={`${idInputClass} w-28`}
                value={value.stars}
                onChange={(e) => onChange({ ...value, stars: e.target.value === "" ? 0 : Number(e.target.value) })}
              />
            </label>
          )}
        </div>
      )}
    </fieldset>
  );
}
