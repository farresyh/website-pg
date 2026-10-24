import Image from "next/image";
import { PaymentChannelIcon } from "@/components/icons/PaymentIcons";
import type { PaymentChannel } from "@/lib/payment-methods";

const CHANNEL_GROUPS: { key: string; label: string }[] = [
  { key: "fpx", label: "Online Banking (FPX)" },
  { key: "duitnow_qr", label: "DuitNow QR" },
  { key: "ewallet", label: "e-Wallet" },
  { key: "card", label: "Debit & Credit Card" },
];

/**
 * The payment method step, grouped by kind, with the CHIP security strip.
 * Shared by the top-up order page and `/joki` (ADR-126 PR 5), moved out
 * of OrderForm unchanged.
 */
export default function PaymentChannelPicker({
  channels,
  selected,
  onSelect,
}: {
  channels: PaymentChannel[];
  selected: string | null;
  onSelect: (channelCode: string) => void;
}) {
  return (
    <>
      {CHANNEL_GROUPS.map((group) => {
        const groupChannels = channels.filter(
          (c) =>
            c.category.toLowerCase() === group.key ||
            (group.key === "duitnow_qr" &&
              (c.category.toLowerCase() === "duitnow_qr" ||
                c.channelCode.toLowerCase().includes("duitnow"))),
        );
        if (groupChannels.length === 0) return null;
        // One channel in the group → the tile is its brand mark
        // alone (the group heading above already names the method,
        // and the mark carries the wordmark). Several channels →
        // keep the text so they stay tellable apart.
        const soloChannel = groupChannels.length === 1;
        return (
          <div key={group.key} className="mb-3.5 last:mb-0">
            <p className="mb-2 font-display text-[11px] font-bold tracking-wide text-on-surface-variant uppercase">
              {group.label}
            </p>
            <div className="flex flex-wrap gap-2">
              {groupChannels.map((channel) => (
                <button
                  key={channel.channelCode}
                  type="button"
                  onClick={() => onSelect(channel.channelCode)}
                  className={`inline-flex min-h-11 items-center gap-2.5 rounded-md border-2 px-3.5 py-2 text-[13px] font-semibold transition-all ${
                    selected === channel.channelCode
                      ? "border-primary bg-primary-fixed neo-sm"
                      : "border-ink bg-surface-container-lowest hover:bg-surface-container-low"
                  }`}
                >
                  <PaymentChannelIcon
                    channelCode={channel.channelCode}
                    category={channel.category}
                    className={
                      soloChannel
                        ? "h-7 w-auto shrink-0"
                        : "h-4.5 w-auto shrink-0"
                    }
                  />
                  <span className={soloChannel ? "sr-only" : undefined}>
                    {channel.label}
                  </span>
                </button>
              ))}
            </div>
          </div>
        );
      })}

      <div className="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-md border-2 border-ink/15 bg-surface-container p-2.5 text-xs text-on-surface-variant">
        <div className="flex items-center gap-2">
          <span className="font-semibold text-on-surface">
            Secured &amp; Powered by
          </span>
          <Image
            src="/images/chip/powered-by-chip-long.svg"
            alt="Powered by CHIP"
            width={130}
            height={18}
            className="h-4 w-auto object-contain"
          />
        </div>
        <span className="text-[11px] text-on-surface-variant">
          BNM Compliant • 256-bit SSL
        </span>
      </div>
    </>
  );
}
