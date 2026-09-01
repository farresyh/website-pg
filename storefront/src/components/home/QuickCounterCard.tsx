"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import Image from "next/image";
import { ArrowRight, GameController } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import { QUICK_COUNTER_SLUGS, type Game } from "@/lib/catalog";

const MAX_TILES = 6;

/**
 * ADR-064: Stitch's "Quick Top-Up" widget — a bordered card beside the
 * hero with a game-picker *grid* (no dropdown). Shows the pinned quick
 * games first, padded with the top catalog games so the grid is never
 * sparse; a game's real thumbnail when it has one, a Phosphor icon
 * otherwise. Tap a tile to select, then "Start Top Up".
 */
export default function QuickCounterCard({ games }: { games: Game[] }) {
  const router = useRouter();
  const [selected, setSelected] = useState("");

  const pinned = QUICK_COUNTER_SLUGS.map((slug) => games.find((g) => g.slug === slug)).filter(
    (g): g is Game => Boolean(g),
  );
  const seen = new Set(pinned.map((g) => g.slug));
  const tiles = [...pinned, ...games.filter((g) => !seen.has(g.slug))].slice(0, MAX_TILES);

  function startTopUp() {
    if (!selected) return;
    router.push(`/order/${selected}`);
  }

  return (
    <div className="flex h-full flex-col rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo">
      <h3 className="font-display text-headline-sm">Quick Top-Up</h3>
      <p className="mb-5 mt-0.5 text-[12px] text-on-surface-variant">Select your game to start.</p>

      {tiles.length === 0 ? (
        <p className="my-auto rounded-md border-2 border-dashed border-ink/40 p-6 text-center text-[13px] text-on-surface-variant">
          Games coming soon.
        </p>
      ) : (
        <>
          <label className="mb-2 font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">
            Select Game
          </label>
          <div className="grid grid-cols-3 gap-2">
            {tiles.map((g) => {
              const active = selected === g.slug;
              return (
                <button
                  key={g.slug}
                  type="button"
                  onClick={() => setSelected(g.slug)}
                  aria-pressed={active}
                  className={`flex min-h-11 flex-col items-center justify-start gap-1.5 rounded-md border-2 p-2 text-center transition-colors ${
                    active
                      ? "border-primary bg-primary-fixed"
                      : "border-ink bg-surface-container-lowest hover:bg-surface-container-low"
                  }`}
                >
                  <span className="relative flex h-11 w-11 items-center justify-center overflow-hidden rounded-full border-2 border-ink bg-surface-container">
                    {g.imageUrl ? (
                      <Image src={g.imageUrl} alt="" fill className="object-cover" sizes="44px" />
                    ) : (
                      <GameController size={20} weight="fill" className="text-ink" />
                    )}
                  </span>
                  <span className="line-clamp-2 font-display text-[10px] font-bold leading-tight">{g.name}</span>
                </button>
              );
            })}
          </div>
        </>
      )}

      <Button onClick={startTopUp} className="mt-auto w-full">
        Start Top Up
        <ArrowRight size={16} weight="bold" />
      </Button>
    </div>
  );
}
