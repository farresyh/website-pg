"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import Button from "@/components/ui/Button";
import { QUICK_COUNTER_SLUGS, type Game } from "@/lib/catalog";

export default function QuickCounterCard({ games }: { games: Game[] }) {
  const router = useRouter();
  const [selected, setSelected] = useState("");
  const quickGames = games.filter((g) => QUICK_COUNTER_SLUGS.includes(g.slug));

  function startTopUp() {
    if (!selected) return;
    router.push(`/order/${selected}`);
  }

  return (
    <div className="flex flex-col rounded-2xl border border-border bg-surface p-5 lg:p-6">
      <h3 className="mb-0.5 text-base font-bold">Quick Counter</h3>
      <p className="mb-3.5 text-[11.5px] text-text-muted">Pick a game and start your top-up fast</p>

      <select
        value={selected}
        onChange={(e) => setSelected(e.target.value)}
        className="mb-2.5 min-h-11 w-full rounded-lg border border-border bg-bg px-3 text-sm text-text focus:border-brand focus:outline-none"
      >
        <option value="">Select a game...</option>
        {games.map((g) => (
          <option key={g.slug} value={g.slug}>
            {g.name}
          </option>
        ))}
      </select>

      <div className="mb-3.5 grid grid-cols-2 gap-2">
        {quickGames.map((g) => (
          <button
            key={g.slug}
            onClick={() => setSelected(g.slug)}
            className={`flex min-h-11 items-center gap-2 rounded-lg border px-2.5 text-[12px] font-semibold transition-colors ${
              selected === g.slug ? "border-brand bg-brand/10 text-text" : "border-border text-text-muted"
            }`}
          >
            <span className="h-2 w-2 shrink-0 rounded-full bg-brand" />
            {g.name}
          </button>
        ))}
      </div>

      <Button onClick={startTopUp} className="mt-auto w-full justify-center">
        Start Top Up →
      </Button>
    </div>
  );
}
