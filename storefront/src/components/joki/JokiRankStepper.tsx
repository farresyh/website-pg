"use client";

import Image from "next/image";
import { CaretDown, CaretLeft, CaretRight, CaretUp, Star } from "@phosphor-icons/react/dist/ssr";
import type { JokiRank, JokiTier } from "@/lib/joki";

const ROMAN = ["", "I", "II", "III", "IV", "V", "VI", "VII", "VIII"];

/** One stop on the ladder: a division, the Grading state, or a Mythic band. */
interface Stop {
  tier: JokiTier;
  division: number | null;
  grading: boolean;
}

/** Bottom first: each divisional tier's divisions V … I, then Grading (optional), then the Mythic bands. */
export function jokiStops(tiers: JokiTier[], withGrading: boolean): Stop[] {
  const stops: Stop[] = [];
  for (const tier of tiers.filter((t) => t.kind === "divisional")) {
    for (let d = tier.divisions ?? 0; d >= 1; d--) stops.push({ tier, division: d, grading: false });
  }
  const mythic = tiers.filter((t) => t.kind === "mythic").sort((a, b) => (a.mythic_from_stars ?? 0) - (b.mythic_from_stars ?? 0));
  if (withGrading && mythic[0]) stops.push({ tier: mythic[0], division: null, grading: true });
  for (const tier of mythic) stops.push({ tier, division: null, grading: false });
  return stops;
}

/** The rank a stop starts at: 0★ in a division, Grading, or the band's first Mythic star. */
export function rankAt(stop: Stop): JokiRank {
  if (stop.grading) return { tier_id: stop.tier.id, division: null, stars: null };
  if (stop.division !== null) return { tier_id: stop.tier.id, division: stop.division, stars: 0 };
  return { tier_id: stop.tier.id, division: null, stars: stop.tier.mythic_from_stars ?? 0 };
}

function stopIndex(stops: Stop[], rank: JokiRank): number {
  return stops.findIndex((s) =>
    s.tier.id === rank.tier_id && (s.grading ? rank.stars === null : s.division === rank.division && (s.division !== null || rank.stars !== null)),
  );
}

function stopLabel(stop: Stop): string {
  if (stop.grading) return `${stop.tier.name} (Grading)`;
  return stop.division !== null ? `${stop.tier.name} ${ROMAN[stop.division] ?? stop.division}` : stop.tier.name;
}

/** `big` is the current rank (64px on a phone, 84px up); the faded neighbours (44px) show from `sm` up. */
function RankIcon({ tier, big = false }: { tier: JokiTier; big?: boolean }) {
  // On a phone the arrows take the neighbours' place, so only the current rank shows.
  const size = big ? "h-16 w-16 sm:h-[84px] sm:w-[84px]" : "hidden opacity-35 grayscale sm:flex sm:h-11 sm:w-11";
  return (
    <span className={`flex shrink-0 items-center justify-center ${size}`}>
      {tier.icon_url ? (
        <Image src={tier.icon_url} alt="" width={84} height={84} className="h-full w-full object-contain" unoptimized />
      ) : (
        <span className={`flex h-full w-full items-center justify-center rounded-full border-2 border-ink bg-primary-fixed font-display font-bold uppercase text-primary-on-surface ${big ? "text-lg sm:text-2xl" : "text-[10px] sm:text-sm"}`}>
          {tier.name.split(" ").map((w) => w[0]).join("").slice(0, 2)}
        </span>
      )}
    </span>
  );
}

const stepButton =
  "flex h-10 w-9 shrink-0 sm:h-11 sm:w-11 items-center justify-center rounded-md border-2 border-ink bg-surface-container-lowest transition-all hover:bg-surface-container-low disabled:opacity-30 disabled:hover:bg-surface-container-lowest";

/**
 * ADR-126 decision 3: one side of the joki calculator — ‹ › walk the
 * panel's ladder one division (or Mythic band) at a time, with the ranks
 * either side shown faded; ▲ ▼ set the stars. In Mythic the star count can
 * also be typed, since a band runs 25+ stars, and the band follows the
 * count. The backend checks every value; this only builds the rank.
 */
export default function JokiRankStepper({ id, label, tiers, value, onChange, allowGrading = false, minStop = 0, maxMythicStars }: {
  id: string;
  label: string;
  tiers: JokiTier[];
  value: JokiRank;
  onChange: (rank: JokiRank) => void;
  allowGrading?: boolean;
  /** The lowest stop this side may go to (the target never goes below the start). */
  minStop?: number;
  maxMythicStars: number;
}) {
  const stops = jokiStops(tiers, allowGrading);
  const index = Math.max(0, stopIndex(stops, value));
  const stop = stops[index];
  if (!stop) return null;

  const mythicBands = tiers.filter((t) => t.kind === "mythic");
  const isMythicStars = !stop.grading && stop.division === null;
  const stars = value.stars ?? 0;
  const maxStars = stop.division !== null ? (stop.tier.stars_per_division ?? 1) - 1 : maxMythicStars;

  function go(next: number) {
    const target = stops[next];
    if (!target) return;
    const rank = rankAt(target);
    // Keep the stars when moving between divisions, as far as they fit.
    if (target.division !== null && stop.division !== null) rank.stars = Math.min(stars, (target.tier.stars_per_division ?? 1) - 1);
    onChange(rank);
  }

  function setStars(next: number) {
    const clamped = Math.max(0, Math.min(maxStars, next));
    if (!isMythicStars) {
      onChange({ ...value, stars: clamped });
      return;
    }
    // In Mythic the band is whatever the star count falls in.
    const band = [...mythicBands].reverse().find((t) => (t.mythic_from_stars ?? 0) <= clamped) ?? stop.tier;
    onChange({ tier_id: band.id, division: null, stars: clamped });
  }

  const prev = stops[index - 1];
  const next = stops[index + 1];

  return (
    <div className="flex flex-col items-center gap-3" role="group" aria-label={label}>
      <p className="text-center font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">{label}</p>

      {/* The arrows sit over the faded neighbours, so both sides fit side by side on a phone. */}
      <div className="relative flex w-full items-center justify-center">
        <button type="button" className={`${stepButton} absolute left-0 z-10`} onClick={() => go(index - 1)} disabled={index <= minStop} aria-label={`${label}: lower rank`}>
          <CaretLeft size={18} weight="bold" />
        </button>
        <div className="flex items-center justify-center gap-0.5 sm:gap-1">
          {prev ? <RankIcon tier={prev.tier} /> : <span className="hidden sm:block sm:w-11" />}
          <RankIcon tier={stop.tier} big />
          {next ? <RankIcon tier={next.tier} /> : <span className="hidden sm:block sm:w-11" />}
        </div>
        <button type="button" className={`${stepButton} absolute right-0 z-10`} onClick={() => go(index + 1)} disabled={index >= stops.length - 1} aria-label={`${label}: higher rank`}>
          <CaretRight size={18} weight="bold" />
        </button>
      </div>

      <p className="text-center font-display text-sm font-bold uppercase tracking-tight sm:text-base" aria-live="polite" data-testid={`${id}-label`}>
        {stopLabel(stop)}
      </p>

      {stop.grading ? (
        <p className="text-xs text-on-surface-variant">Mythic Grading not finished</p>
      ) : (
        <div className="flex flex-col items-center gap-1.5">
          <button type="button" className={stepButton} onClick={() => setStars(stars + 1)} disabled={stars >= maxStars} aria-label={`${label}: more stars`}>
            <CaretUp size={16} weight="bold" />
          </button>
          <label className="flex items-center gap-2 border-b-2 border-ink px-2">
            <Star size={18} weight="fill" className="text-tertiary" aria-hidden="true" />
            <span className="sr-only">{label} stars</span>
            <input
              id={`${id}-stars`}
              type="number"
              inputMode="numeric"
              min={0}
              max={maxStars}
              value={stars}
              readOnly={!isMythicStars}
              onChange={(e) => setStars(e.target.value === "" ? 0 : Number(e.target.value))}
              className="w-14 bg-transparent text-center font-mono text-xl sm:w-16 sm:text-2xl font-bold [appearance:textfield] focus:outline-none [&::-webkit-inner-spin-button]:appearance-none"
            />
          </label>
          <button type="button" className={stepButton} onClick={() => setStars(stars - 1)} disabled={stars <= 0} aria-label={`${label}: fewer stars`}>
            <CaretDown size={16} weight="bold" />
          </button>
        </div>
      )}
    </div>
  );
}
