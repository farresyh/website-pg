"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { ArrowRight, GameController } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import { QUICK_COUNTER_SLUGS, type Game } from "@/lib/catalog";

/**
 * ADR-064: Stitch's "Quick Top-Up" widget — a bordered card beside the
 * hero. Fast game picker (grid of the pinned games + a full dropdown)
 * then straight into the order flow.
 */
export default function QuickCounterCard({ games }: { games: Game[] }) {
  const router = useRouter();
  const [selected, setSelected] = useState("");
  const quickGames = games.filter((g) => QUICK_COUNTER_SLUGS.includes(g.slug));

  function startTopUp() {
    if (!selected) return;
    router.push(`/order/${selected}`);
  }

  return (
    <div className="flex h-full flex-col rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo">
      <h3 className="font-display text-headline-sm">Quick Top-Up</h3>
      <p className="mb-5 mt-0.5 text-[12px] text-on-surface-variant">Select your game to start.</p>

      <label className="mb-2 font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">
        Select Game
      </label>
      <div className="mb-3 grid grid-cols-3 gap-2">
        {quickGames.map((g) => (
          <button
            key={g.slug}
            onClick={() => setSelected(g.slug)}
            className={`flex min-h-16 flex-col items-center justify-center gap-1.5 rounded-md border-2 px-1 py-2 text-center transition-colors ${
              selected === g.slug ? "border-primary bg-primary-fixed" : "border-ink bg-surface-container-lowest hover:bg-surface-container-low"
            }`}
          >
            <GameController size={20} weight="fill" className="text-ink" />
            <span className="font-display text-[10px] font-bold leading-tight">{g.name}</span>
          </button>
        ))}
      </div>

      <select
        value={selected}
        onChange={(e) => setSelected(e.target.value)}
        className="mb-4 min-h-11 w-full rounded-md border-2 border-ink bg-surface-container-lowest px-3 text-sm text-on-surface focus:border-secondary focus:outline-none"
      >
        <option value="">Or pick from all games…</option>
        {games.map((g) => (
          <option key={g.slug} value={g.slug}>
            {g.name}
          </option>
        ))}
      </select>

      <Button
        onClick={startTopUp}
        className="mt-auto w-full"
        startIcon={undefined}
      >
        Start Top Up
        <ArrowRight size={16} weight="bold" />
      </Button>
    </div>
  );
}
