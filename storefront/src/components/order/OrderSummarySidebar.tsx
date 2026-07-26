import { LockSimple } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import type { Game, GamePackage } from "@/lib/catalog";

interface OrderSummarySidebarProps {
  game: Game;
  selectedPackage: GamePackage | null;
  playerId: string;
  serverId: string;
  ready: boolean;
  onReview: () => void;
}

export default function OrderSummarySidebar({ game, selectedPackage, playerId, serverId, ready, onReview }: OrderSummarySidebarProps) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-5 lg:sticky lg:top-20">
      <h3 className="mb-4 text-base font-bold">Order Summary</h3>
      <div className="mb-4 flex flex-col gap-2.5 text-[13.5px]">
        <Row k="Product" v={game.name} />
        <Row k="Package" v={selectedPackage?.name ?? "—"} dim={!selectedPackage} />
        <Row k="Player ID" v={playerId ? (serverId ? `${playerId} (${serverId})` : playerId) : "—"} dim={!playerId} />
      </div>
      <hr className="mb-4 border-border" />
      <div className="mb-4 flex items-center justify-between">
        <span className="text-[15px] font-extrabold">Total</span>
        <span className="text-xl font-extrabold text-brand-light">RM{(selectedPackage?.priceRm ?? 0).toFixed(2)}</span>
      </div>
      <Button onClick={onReview} disabled={!ready} className="w-full justify-center">
        {ready ? (
          `Review & Pay RM${(selectedPackage?.priceRm ?? 0).toFixed(2)}`
        ) : (
          <>
            <LockSimple size={14} /> Complete steps to continue
          </>
        )}
      </Button>
    </div>
  );
}

function Row({ k, v, dim }: { k: string; v: string; dim?: boolean }) {
  return (
    <div className="flex items-start justify-between gap-3">
      <span className="text-text-muted">{k}</span>
      <span className={`text-right font-semibold ${dim ? "text-text-muted" : "text-text"}`}>{v}</span>
    </div>
  );
}
