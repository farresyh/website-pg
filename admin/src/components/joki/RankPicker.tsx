"use client";

import { Input } from "@/components/ui/input";
import { OptionSelect } from "@/components/ui/option-select";
import type { JokiRank, JokiTier } from "@/lib/joki";

const ROMAN = ["", "I", "II", "III", "IV", "V", "VI", "VII", "VIII"];
const GRADING = "grading";

type PickerTier = Pick<JokiTier, "id" | "name" | "kind" | "divisions" | "stars_per_division" | "mythic_from_stars">;

/**
 * ADR-126 decision 3: a rank on the panel's model. Divisional tiers pick a
 * division (V … I) and 0 … n−1 stars; a Mythic tier takes its star count
 * on the Mythic scale. `allowGrading` adds "Mythic (Grading)" (stars null),
 * the start of a route. The backend re-checks it.
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
  const isGrading = value.stars === null && tier?.id === lowestMythic?.id;

  const tierOptions = tiers.flatMap((t) => [
    { label: t.name, value: String(t.id) },
    ...(allowGrading && t.id === lowestMythic?.id ? [{ label: `${t.name} (Grading)`, value: GRADING }] : []),
  ]);

  function pickTier(v: string) {
    if (v === GRADING && lowestMythic) {
      onChange({ tier_id: lowestMythic.id, division: null, stars: null });
      return;
    }
    const next = tiers.find((t) => t.id === Number(v));
    if (!next) return;
    onChange(next.kind === "divisional"
      ? { tier_id: next.id, division: next.divisions, stars: 0 }
      : { tier_id: next.id, division: null, stars: next.mythic_from_stars ?? 0 });
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <OptionSelect id={`${idPrefix}-tier`} ariaLabel="Tier" className="min-w-[11rem]" value={isGrading ? GRADING : String(value.tier_id)} options={tierOptions} onChange={pickTier} />
      {tier?.kind === "divisional" && (
        <>
          <OptionSelect
            ariaLabel="Division"
            className="min-w-[5rem]"
            value={String(value.division ?? "")}
            options={Array.from({ length: tier.divisions ?? 0 }, (_, i) => (tier.divisions ?? 0) - i).map((d) => ({ label: ROMAN[d] ?? String(d), value: String(d) }))}
            onChange={(v) => onChange({ ...value, division: Number(v) })}
          />
          <OptionSelect
            ariaLabel="Stars"
            className="min-w-[5rem]"
            value={String(value.stars ?? 0)}
            options={Array.from({ length: tier.stars_per_division ?? 0 }, (_, s) => ({ label: `${s}★`, value: String(s) }))}
            onChange={(v) => onChange({ ...value, stars: Number(v) })}
          />
        </>
      )}
      {tier?.kind === "mythic" && !isGrading && (
        <div className="flex items-center gap-2">
          <div className="w-24">
            <Input aria-label="Mythic stars" type="number" min={0} value={String(value.stars ?? 0)} onChange={(e) => onChange({ ...value, stars: Number(e.target.value) })} />
          </div>
          <span className="text-sm text-ink-muted">★</span>
        </div>
      )}
    </div>
  );
}
