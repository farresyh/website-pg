import type { GameDetail, GamePackage } from "@/lib/catalog";
import type { PaymentChannel } from "@/lib/payment-methods";

interface GameFactLineProps {
  game: GameDetail;
  packages: GamePackage[];
  paymentChannels: PaymentChannel[];
}

const EXTRA_FIELD_LABEL = { zone_id: "Zone ID", server_id: "Server ID" } as const;

/**
 * ADR-120 decision 12: one plain-text line of concrete facts, generated
 * from data the page already has — the sentence an AI answer quotes for
 * "how much is a {game} top up". Prices are the guest Standard price the
 * cards show (same source as the Product JSON-LD offers); a payment
 * method's transaction fee is added at review, so it's not folded in.
 */
export default function GameFactLine({ game, packages, paymentChannels }: GameFactLineProps) {
  const prices = packages.map((pkg) => pkg.priceRm);
  const facts: string[] = [];

  if (prices.length > 0) {
    const low = Math.min(...prices).toFixed(2);
    const high = Math.max(...prices).toFixed(2);
    facts.push(
      low === high
        ? `${game.name} top up: RM${low}`
        : `${game.name} top up from RM${low} to RM${high} (${prices.length} packages)`,
    );
  }
  facts.push(`${game.deliveryMode === "instant" ? "Instant delivery" : "Manual processing"} (${game.deliverySubtext})`);
  facts.push(game.extraField ? `Needs your Player ID and ${EXTRA_FIELD_LABEL[game.extraField]}` : "Needs your Player ID");
  if (paymentChannels.length > 0) {
    facts.push(`Pay with ${paymentChannels.map((channel) => channel.label).join(", ")}`);
  }

  return <p className="-mt-3 mb-6 text-[13px] leading-relaxed text-on-surface-variant">{facts.join(" · ")}.</p>;
}
