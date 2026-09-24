import Image from "next/image";
import { Lightning, Clock } from "@phosphor-icons/react/dist/ssr";
import type { GameDetail } from "@/lib/catalog";

export default function ProductHeaderCard({ game }: { game: GameDetail }) {
  const isInstant = game.deliveryMode === "instant";
  return (
    <div className="mb-6 flex items-center gap-5 rounded-lg border-2 border-ink bg-surface-container-lowest p-5 neo">
      <div
        className="relative flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-md border-2 border-ink bg-surface-variant neo-sm"
        style={{
          backgroundImage:
            "repeating-linear-gradient(45deg, transparent, transparent 9px, rgb(25 25 47 / 0.05) 9px, rgb(25 25 47 / 0.05) 10px)",
        }}
      >
        {game.imageUrl ? (
          <Image src={game.imageUrl} alt={game.name} fill className="object-cover" sizes="64px" priority />
        ) : (
          <span className="font-display text-2xl font-bold text-ink/70">{game.name.charAt(0).toUpperCase()}</span>
        )}
      </div>
      <div>
        <h1 className="font-display text-xl font-bold uppercase tracking-tight lg:text-2xl">{game.name}</h1>
        {game.publisher && <p className="text-[13px] text-on-surface-variant">{game.publisher}</p>}
        <div className="mt-2 flex flex-wrap items-center gap-2.5">
          <span className="inline-flex items-center gap-1.5 rounded-full border border-ink bg-surface-container px-2.5 py-0.5 font-display text-[11px] font-bold uppercase tracking-wide">
            {isInstant ? (
              <Lightning size={12} weight="fill" className="text-primary-on-surface" />
            ) : (
              <Clock size={12} weight="fill" className="text-secondary" />
            )}
            {isInstant ? "Instant Delivery" : "Manual Processing"}
          </span>
          <span className="text-xs text-on-surface-variant">{game.deliverySubtext}</span>
        </div>
      </div>
    </div>
  );
}
