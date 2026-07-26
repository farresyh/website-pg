import { PROMOTIONS } from "@/lib/placeholder-data";

export default function PromotionsSection() {
  return (
    <section id="promotions" className="mx-auto max-w-[1200px] px-4 py-10">
      <div className="mb-5 flex flex-wrap items-baseline justify-between gap-3">
        <h2 className="font-display text-[22px] tracking-wide">This Week&apos;s Promotions</h2>
      </div>
      <div className="grid gap-3.5 lg:grid-cols-3">
        {PROMOTIONS.map((promo) => (
          <div key={promo.id} className="rounded-xl border border-border bg-surface p-4.5">
            <div className="mb-2.5 flex items-center gap-2.5">
              <span className="rounded-full bg-amber/15 px-2.5 py-1 text-[11px] font-bold tracking-wide text-amber uppercase">
                {promo.badge}
              </span>
              <span className="text-xs text-text-muted">Ends: {promo.endsAt}</span>
            </div>
            <h3 className="mb-1.5 text-[15px] font-bold">{promo.title}</h3>
            <p className="text-[13px] leading-relaxed text-text-muted">{promo.description}</p>
          </div>
        ))}
      </div>
    </section>
  );
}
