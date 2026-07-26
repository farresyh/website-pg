import Image from "next/image";
import Button from "@/components/ui/Button";
import type { Game } from "@/lib/catalog";

/**
 * `imageUrl` is nullable, same as the real `games.image_url` column —
 * falls back to a gradient-initial tile instead of a broken <img>.
 * No `featured`/`publisher` column exists on the real Game model
 * (unlike the old placeholder), so both render conditionally rather
 * than assuming they're always present.
 */
export default function ProductCard({ game }: { game: Game }) {
  return (
    <div className="flex flex-col overflow-hidden rounded-xl border border-border bg-surface transition-colors">
      <div className="relative aspect-16/10 bg-bg-deep">
        {game.imageUrl ? (
          <Image src={game.imageUrl} alt={game.name} fill className="object-cover" />
        ) : (
          <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-surface-2 to-bg-deep">
            <span className="font-display text-3xl text-border">{game.name.charAt(0)}</span>
          </div>
        )}
      </div>
      <div className="flex flex-col gap-1.5 p-3.5">
        <span className="text-sm font-bold">{game.name}</span>
        {game.publisher && <span className="text-xs text-text-muted">{game.publisher}</span>}
        <div className="mt-1.5 flex items-end justify-between gap-2">
          <span>
            <span className="block text-[11px] text-text-muted">From</span>
            <span className="text-[15px] font-extrabold">
              {game.priceFromRm !== null ? `RM${game.priceFromRm.toFixed(2)}` : "—"}
            </span>
          </span>
          <Button href={`/order/${game.slug}`} size="sm" variant="outline">
            Top Up
          </Button>
        </div>
      </div>
    </div>
  );
}
