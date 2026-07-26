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
    <section className="border-y border-border bg-surface py-8">
      <div className="mx-auto grid max-w-[1200px] grid-cols-1 gap-5 px-4 lg:grid-cols-3">
        {ITEMS.map(({ icon: Icon, title, sub }) => (
          <div key={title} className="flex items-center gap-3.5">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-border bg-bg text-brand-light">
              <Icon size={20} />
            </div>
            <div>
              <p className="text-sm font-bold">{title}</p>
              <p className="text-xs text-text-muted">{sub}</p>
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}
