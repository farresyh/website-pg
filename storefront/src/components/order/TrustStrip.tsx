import { Lightning, ShieldCheck, ChatCircleDots } from "@phosphor-icons/react/dist/ssr";

/**
 * Dropped the reference's "100K+ Successful Transactions" stat — an
 * unverifiable, made-up number we can't back up. 3 honest items
 * instead of 4 fabricated ones.
 */
const ITEMS = [
  { icon: Lightning, title: "Instant Delivery", sub: "Automated within 3 minutes" },
  { icon: ChatCircleDots, title: "24/7 Support", sub: "WhatsApp & chat assistance" },
  { icon: ShieldCheck, title: "Secure Payment", sub: "FPX & local e-wallets" },
];

export default function TrustStrip() {
  return (
    <section className="border-y-2 border-ink bg-surface-container py-9">
      <div className="mx-auto grid max-w-[1200px] grid-cols-1 gap-5 px-4 lg:grid-cols-3">
        {ITEMS.map(({ icon: Icon, title, sub }) => (
          <div key={title} className="flex items-center gap-3.5">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-md border-2 border-ink bg-secondary-fixed-dim text-ink neo-sm">
              <Icon size={20} weight="fill" />
            </div>
            <div>
              <p className="font-display text-sm font-bold">{title}</p>
              <p className="text-xs text-on-surface-variant">{sub}</p>
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}
