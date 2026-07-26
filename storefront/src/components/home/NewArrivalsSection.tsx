import Link from "next/link";
import { PLACEHOLDER_GAMES } from "@/lib/placeholder-data";

/**
 * Sorted by `addedAt` (stands in for `games.created_at`) — this
 * section needs no dedicated backend feature, unlike Promotions: the
 * real public catalog endpoint can serve this with
 * `ORDER BY created_at DESC LIMIT 4` on data that already exists.
 */
export default function NewArrivalsSection() {
  const newest = [...PLACEHOLDER_GAMES].sort((a, b) => b.addedAt.localeCompare(a.addedAt)).slice(0, 4);

  return (
    <section className="mx-auto max-w-[1200px] px-4 py-10">
      <h2 className="font-display mb-5 text-[22px] tracking-wide">New Arrivals</h2>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {newest.map((game) => (
          <Link
            key={game.slug}
            href={`/order/${game.slug}`}
            className="flex min-h-11 flex-col gap-2 rounded-lg border border-border bg-surface p-3 transition-colors hover:border-brand"
          >
            <span className="flex items-center justify-between gap-1.5">
              <span className="text-[13px] font-bold">{game.name}</span>
              <span className="rounded-full bg-brand-dark px-2 py-0.5 text-[10px] font-bold text-brand-light">New</span>
            </span>
            <span className="text-[13px] font-extrabold">From RM{game.priceFromRm.toFixed(2)}</span>
          </Link>
        ))}
      </div>
    </section>
  );
}
