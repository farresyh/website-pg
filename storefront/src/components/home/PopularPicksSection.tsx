"use client";

import { Lightning } from "@phosphor-icons/react/dist/ssr";
import ProductCard from "@/components/home/ProductCard";
import type { Game } from "@/lib/catalog";
import { useSearch } from "@/context/SearchContext";

export default function PopularPicksSection({ games: allGames }: { games: Game[] }) {
  const { query } = useSearch();
  const normalized = query.trim().toLowerCase();
  const games = normalized ? allGames.filter((g) => g.name.toLowerCase().includes(normalized)) : allGames;

  return (
    <section id="popular-picks" className="mx-auto max-w-[1200px] px-4 py-10">
      <div className="mb-5 flex flex-wrap items-baseline justify-between gap-3">
        <h2 className="font-display text-[22px] tracking-wide">Popular Picks</h2>
        <a href="#popular-picks" className="text-[13px] font-bold text-brand-light">
          View All Products ›
        </a>
      </div>
      <p className="mb-5 -mt-2.5 flex items-center gap-1.5 text-[13px] text-text-muted">
        <Lightning size={14} weight="fill" />
        All items below are delivered automatically within 3 minutes
      </p>

      {games.length === 0 ? (
        <p className="rounded-xl border border-border bg-surface p-8 text-center text-sm text-text-muted">
          No games match &quot;{query}&quot; — try another search.
        </p>
      ) : (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {games.map((game) => (
            <ProductCard key={game.slug} game={game} />
          ))}
        </div>
      )}
    </section>
  );
}
