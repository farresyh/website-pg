import Image from "next/image";
import Link from "next/link";
import PlaceholderTile from "@/components/ui/PlaceholderTile";
import type { Game } from "@/lib/catalog";

/**
 * `imageUrl` is nullable, same as the real `games.image_url` column —
 * falls back to a branded PlaceholderTile, never a broken <img>.
 * The whole card is the link target (one primary action per card,
 * ADR-064). `publisher` renders conditionally — no such column exists
 * on the real Game model.
 */
export default function ProductCard({ game }: { game: Game }) {
  return (
    <Link
      href={`/order/${game.slug}`}
      className="group flex flex-col overflow-hidden rounded-lg border-2 border-ink bg-surface-container-lowest neo neo-hover transition-all"
    >
      <div className="relative aspect-16/10">
        {game.imageUrl ? (
          <Image src={game.imageUrl} alt={game.name} fill className="border-b-2 border-ink object-cover" />
        ) : (
          <PlaceholderTile label={game.name} />
        )}
      </div>
      <div className="flex flex-col gap-1.5 p-3.5">
        <span className="font-display text-[15px] font-bold leading-tight">{game.name}</span>
        {game.publisher && <span className="text-xs text-on-surface-variant">{game.publisher}</span>}
        <div className="mt-2 flex items-end justify-between gap-2 border-t border-dashed border-ink/40 pt-2">
          <span className="text-[11px] text-on-surface-variant">Starting from</span>
          <span className="font-mono text-[15px] font-bold text-primary">
            {game.priceFromRm !== null ? `RM${game.priceFromRm.toFixed(2)}` : "-"}
          </span>
        </div>
      </div>
    </Link>
  );
}
