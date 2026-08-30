import { LockSimple } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import type { CheckoutTotalPreview } from "@/lib/checkout";
import type { Game, GamePackage } from "@/lib/catalog";

interface OrderSummarySidebarProps {
  game: Game;
  selectedPackage: GamePackage | null;
  /**
   * Real breakdown from CheckoutTotalService (bug fix, 2026-08-30) — null
   * until a package AND payment channel are both chosen (a channel's fee
   * only applies once one is selected) or while a fresh preview is still
   * in flight. Falls back to the package price alone in that gap, rather
   * than showing a Transaction Fee row with a stale or fabricated number.
   */
  preview: CheckoutTotalPreview | null;
  playerId: string;
  serverId: string;
  ready: boolean;
  onReview: () => void;
}

export default function OrderSummarySidebar({
  game,
  selectedPackage,
  preview,
  playerId,
  serverId,
  ready,
  onReview,
}: OrderSummarySidebarProps) {
  const totalRm = preview ? preview.final_amount_sen / 100 : (selectedPackage?.priceRm ?? 0);

  return (
    <div className="rounded-2xl border border-border bg-surface p-5">
      <h3 className="mb-4 text-base font-bold">Order Summary</h3>
      <div className="mb-4 flex flex-col gap-2.5 text-[13.5px]">
        <Row k="Product" v={game.name} />
        <Row k="Package" v={selectedPackage?.name ?? "—"} dim={!selectedPackage} />
        <Row k="Player ID" v={playerId ? (serverId ? `${playerId} (${serverId})` : playerId) : "—"} dim={!playerId} />
      </div>
      <hr className="mb-4 border-border" />
      <div className="mb-4 flex flex-col gap-2 text-[13.5px]">
        <Row k="Package Price" v={`RM${(preview ? preview.selling_price_sen / 100 : (selectedPackage?.priceRm ?? 0)).toFixed(2)}`} />
        {preview && <Row k="Transaction Fee" v={`RM${(preview.transaction_fee_sen / 100).toFixed(2)}`} />}
        <div className="flex items-center justify-between pt-1">
          <span className="text-[15px] font-extrabold">Total</span>
          <span className="text-xl font-extrabold text-brand-light">RM{totalRm.toFixed(2)}</span>
        </div>
      </div>
      <Button onClick={onReview} disabled={!ready} className="w-full justify-center">
        {ready ? (
          `Review & Pay RM${totalRm.toFixed(2)}`
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
