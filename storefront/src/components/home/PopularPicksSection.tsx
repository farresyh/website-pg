"use client";

import { Lightning } from "@phosphor-icons/react/dist/ssr";
import ProductCard from "@/components/home/ProductCard";
import SectionHeading from "@/components/home/SectionHeading";
import type { Game } from "@/lib/catalog";
import { useSearch } from "@/context/SearchContext";

export default function PopularPicksSection({ games: allGames }: { games: Game[] }) {
  const { query } = useSearch();
  const normalized = query.trim().toLowerCase();
  const games = normalized ? allGames.filter((g) => g.name.toLowerCase().includes(normalized)) : allGames;

  return (
    <section id="popular-picks" className="mx-auto max-w-[1200px] px-4 py-9 lg:py-12">
      <SectionHeading title="Popular Picks" link={{ label: "View All", href: "#popular-picks" }} className="mb-3" />
      <p className="mb-6 flex items-center gap-1.5 text-[13px] text-on-surface-variant">
        <Lightning size={14} weight="fill" className="text-primary-on-surface" />
        All items below are delivered automatically within 3 minutes
      </p>

      {games.length === 0 ? (
        <p className="rounded-lg border-2 border-ink bg-surface-container-lowest p-8 text-center text-sm text-on-surface-variant neo">
          No games match &quot;{query}&quot;. Try another search.
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
