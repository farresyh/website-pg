import Image from "next/image";
import Button from "@/components/ui/Button";
import type { PlaceholderGame } from "@/lib/placeholder-data";

/**
 * `imageUrl` is nullable, same as the real `games.image_url` column —
 * falls back to a gradient-initial tile instead of a broken <img>, so
 * this behaves identically once wired to real (also-nullable) data.
 * The one `featured` card in the grid gets a brand-light border/glow
 * so the grid has a single focal point instead of 8 identical cards.
 */
export default function ProductCard({ game }: { game: PlaceholderGame }) {
  return (
    <div
      className={`flex flex-col overflow-hidden rounded-xl border bg-surface transition-colors ${
        game.featured ? "border-brand-light shadow-[0_0_0_1px_rgba(108,209,93,0.4)]" : "border-border"
      }`}
    >
      <div className="relative aspect-16/10 bg-bg-deep">
        {game.imageUrl ? (
          <Image src={game.imageUrl} alt={game.name} fill className="object-cover" />
        ) : (
          <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-surface-2 to-bg-deep">
            <span className="font-display text-3xl text-border">{game.name.charAt(0)}</span>
          </div>
        )}
        {game.featured && (
          <span className="absolute top-2 left-2 rounded-full border border-brand-light bg-bg/80 px-2.5 py-1 text-[11px] font-bold tracking-wide text-brand-light uppercase">
            Bestseller
          </span>
        )}
      </div>
      <div className="flex flex-col gap-1.5 p-3.5">
        <span className="text-sm font-bold">{game.name}</span>
        <span className="text-xs text-text-muted">{game.publisher}</span>
        <div className="mt-1.5 flex items-end justify-between gap-2">
          <span>
            <span className="block text-[11px] text-text-muted">From</span>
            <span className="text-[15px] font-extrabold">RM{game.priceFromRm.toFixed(2)}</span>
          </span>
          <Button href={`/order/${game.slug}`} size="sm" variant="outline">
            Top Up
          </Button>
        </div>
      </div>
    </div>
  );
}
