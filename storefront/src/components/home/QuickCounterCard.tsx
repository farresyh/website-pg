"use client";

import { useRouter } from "next/navigation";
import Image from "next/image";
import { ArrowRight, GameController } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import { type Game } from "@/lib/catalog";

const MAX_TILES = 6;

/**
 * ADR-064: Stitch's "Quick Top-Up" widget — a bordered card beside the
 * hero with a game-picker *grid* (no dropdown). GAME-6 (2026-09-13):
 * tiles are simply the first MAX_TILES games in `games`, the same
 * admin-ordered list every other catalog surface uses (`sort_order`,
 * CatalogController::index()) — previously a separate hardcoded
 * QUICK_COUNTER_SLUGS shortlist that only a developer could change.
 * Admin now controls this by dragging a game to the front in
 * /admin/games' "Reorder Games", one control surface instead of two.
 * A game's real thumbnail when it has one, a Phosphor icon otherwise.
 * Tap a tile to select, then "Start Top Up".
 */
export default function QuickCounterCard({ games }: { games: Game[] }) {
  const router = useRouter();

  const tiles = games.slice(0, MAX_TILES);

  return (
    <div className="flex h-full flex-col rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo">
      <h3 className="font-display text-headline-sm">Quick Top-Up</h3>
      <p className="mb-5 mt-0.5 text-[12px] text-on-surface-variant">Select your game to start.</p>

      {tiles.length === 0 ? (
        <p className="mt-auto mb-6 rounded-md border-2 border-dashed border-ink/40 p-6 text-center text-[13px] text-on-surface-variant">
          Games coming soon.
        </p>
      ) : (
        <>
          <label className="mb-2 font-display text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">
            Select Game
          </label>
          <div className="grid grid-cols-3 gap-2">
            {tiles.map((g) => (
              <button
                key={g.slug}
                type="button"
                onClick={() => router.push(`/order/${g.slug}`)}
                className="flex min-h-11 flex-col items-center justify-start gap-1.5 rounded-md border-2 border-ink bg-surface-container-lowest p-2 text-center transition-all neo-hover hover:border-primary hover:bg-surface-container-low cursor-pointer"
              >
                <span className="relative flex h-11 w-11 items-center justify-center overflow-hidden rounded-full border-2 border-ink bg-surface-container">
                  {g.imageUrl ? (
                    <Image src={g.imageUrl} alt="" fill className="object-cover" sizes="44px" />
                  ) : (
                    <GameController size={20} weight="fill" className="text-on-surface" />
                  )}
                </span>
                <span className="line-clamp-2 font-display text-[10px] font-bold leading-tight">{g.name}</span>
              </button>
            ))}
          </div>
        </>
      )}

      <Button href="/#popular-picks" variant="primary" className="mt-auto w-full">
        View All Games
        <ArrowRight size={16} weight="bold" />
      </Button>
    </div>
  );
}
