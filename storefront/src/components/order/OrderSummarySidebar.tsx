import { memo } from "react";
import { LockSimple, ShieldCheck } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import type { CheckoutTotalPreview } from "@/lib/checkout";
import type { Game, GamePackage } from "@/lib/catalog";

interface OrderSummarySidebarProps {
  game: Game;
  selectedPackage: GamePackage | null;
  /**
   * Real breakdown from CheckoutTotalService (bug fix, 2026-08-30) — null
   * until a package AND payment channel are both chosen, or while a fresh
   * preview is still in flight. Falls back to the package price alone in
   * that gap, never a stale or fabricated Transaction Fee row.
   */
  preview: CheckoutTotalPreview | null;
  playerId: string;
  serverId: string;
  ready: boolean;
  onReview: () => void;
}

function OrderSummarySidebar({
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
    <div className="rounded-lg border-2 border-ink bg-surface-container-lowest p-5 neo-lg">
      <h3 className="mb-4 border-b-2 border-ink pb-3 font-display text-[15px] font-bold uppercase tracking-wide">
        Order Summary
      </h3>
      <div className="mb-4 flex flex-col gap-2.5 text-[13px]">
        <Row k="Product" v={game.name} />
        <Row k="Package" v={selectedPackage?.name ?? "-"} dim={!selectedPackage} />
        <Row
          k="Player ID"
          v={playerId ? (serverId ? `${playerId} (${serverId})` : playerId) : "-"}
          dim={!playerId}
          mono
        />
      </div>
      <div className="mb-4 flex flex-col gap-2 border-t border-dashed border-ink/40 pt-4 text-[13px]">
        <Row
          k="Package Price"
          v={`RM${(preview ? preview.selling_price_sen / 100 : (selectedPackage?.priceRm ?? 0)).toFixed(2)}`}
          mono
        />
        {preview && <Row k="Transaction Fee" v={`+ RM${(preview.transaction_fee_sen / 100).toFixed(2)}`} mono />}
        <div className="mt-2 flex items-end justify-between border-t-2 border-ink pt-3">
          <span className="font-display text-[15px] font-bold uppercase">Total</span>
          <span className="font-mono text-2xl font-bold text-primary">RM{totalRm.toFixed(2)}</span>
        </div>
      </div>
      <Button onClick={onReview} disabled={!ready} className="w-full">
        {ready ? (
          <>
            <LockSimple size={14} weight="fill" /> Review &amp; Pay RM{totalRm.toFixed(2)}
          </>
        ) : (
          <>
            <LockSimple size={14} /> Complete steps to continue
          </>
        )}
      </Button>
      <p className="mt-3 flex items-center justify-center gap-1.5 text-[11px] text-on-surface-variant">
        <ShieldCheck size={13} weight="fill" />
        Secure encrypted checkout
      </p>
    </div>
  );
}

// `memo` (ADR-071 PR2): skips re-render while the visitor types in the
// Review Modal's contact fields — none of this component's props change
// with that. Player-ID / preview changes still flow through (props do
// change then).
export default memo(OrderSummarySidebar);

function Row({ k, v, dim, mono }: { k: string; v: string; dim?: boolean; mono?: boolean }) {
  return (
    <div className="flex items-start justify-between gap-3">
      <span className="text-on-surface-variant">{k}</span>
      <span
        className={`text-right font-semibold ${mono ? "font-mono" : ""} ${dim ? "text-on-surface-variant" : "text-on-surface"}`}
      >
        {v}
      </span>
    </div>
  );
}
