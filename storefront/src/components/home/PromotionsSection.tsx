import SectionHeading from "@/components/home/SectionHeading";
import Badge from "@/components/ui/Badge";
import { PROMOTIONS } from "@/lib/placeholder-data";

export default function PromotionsSection() {
  return (
    <section id="promotions" className="mx-auto max-w-[1200px] px-4 py-12">
      <SectionHeading title="This Week's Promotions" />
      <div className="grid gap-4 lg:grid-cols-3">
        {PROMOTIONS.map((promo) => (
          <div
            key={promo.id}
            className="flex flex-col rounded-lg border-2 border-ink bg-surface-container-lowest p-5 neo"
          >
            <div className="mb-3 flex flex-wrap items-center gap-2.5">
              <Badge tone="warning" pill>
                {promo.badge}
              </Badge>
              <span className="text-xs text-on-surface-variant">Ends: {promo.endsAt}</span>
            </div>
            <h3 className="mb-1.5 font-display text-headline-sm">{promo.title}</h3>
            <p className="text-[13px] leading-relaxed text-on-surface-variant">{promo.description}</p>
          </div>
        ))}
      </div>
    </section>
  );
}
