import Link from "next/link";
import SectionHeading from "@/components/home/SectionHeading";
import Badge from "@/components/ui/Badge";
import type { Game } from "@/lib/catalog";

/**
 * Sorted client-side by `addedAt` (games.created_at) — real data now,
 * still sorted here since the public catalog index is already fetched
 * once for the whole homepage.
 */
export default function NewArrivalsSection({ games }: { games: Game[] }) {
  const newest = [...games].sort((a, b) => b.addedAt.localeCompare(a.addedAt)).slice(0, 4);

  return (
    <section className="mx-auto max-w-[1200px] px-4 py-12">
      <SectionHeading title="New Arrivals" />
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        {newest.map((game) => (
          <Link
            key={game.slug}
            href={`/order/${game.slug}`}
            className="flex min-h-11 flex-col gap-2.5 rounded-lg border-2 border-ink bg-surface-container-lowest p-4 neo neo-hover transition-all"
          >
            <span className="flex items-start justify-between gap-1.5">
              <span className="font-display text-[13px] font-bold leading-tight">{game.name}</span>
              <Badge tone="new">New</Badge>
            </span>
            <span className="font-mono text-[13px] font-bold text-primary">
              {game.priceFromRm !== null ? `From RM${game.priceFromRm.toFixed(2)}` : "Coming soon"}
            </span>
          </Link>
        ))}
      </div>
    </section>
  );
}
