import { Lightning } from "@phosphor-icons/react/dist/ssr";
import type { PlaceholderGame } from "@/lib/placeholder-data";

export default function ProductHeaderCard({ game }: { game: PlaceholderGame }) {
  return (
    <div className="mb-5 flex items-center gap-5 rounded-2xl border border-border bg-surface p-5">
      <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-surface-2 to-bg-deep">
        <span className="font-display text-2xl text-border">{game.name.charAt(0)}</span>
      </div>
      <div>
        <h1 className="font-display text-xl tracking-wide">{game.name}</h1>
        <p className="text-[13px] text-text-muted">{game.publisher}</p>
        <div className="mt-2 flex flex-wrap items-center gap-2.5">
          <span className="flex items-center gap-1.5 rounded-md bg-brand-dark px-2 py-1 text-[11px] font-bold text-brand-light">
            <Lightning size={12} weight="fill" />
            Instant Delivery
          </span>
          <span className="text-xs text-text-muted">Average delivery: 1–3 minutes</span>
        </div>
      </div>
    </div>
  );
}
