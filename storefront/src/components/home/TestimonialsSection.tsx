import { Star } from "@phosphor-icons/react/dist/ssr";
import SectionHeading from "@/components/home/SectionHeading";
import Badge from "@/components/ui/Badge";
import { TESTIMONIALS } from "@/lib/placeholder-data";

export default function TestimonialsSection() {
  return (
    <section className="mx-auto max-w-[1200px] px-4 py-12">
      <SectionHeading title="What Customers Say" />
      <div className="grid gap-4 lg:grid-cols-3">
        {TESTIMONIALS.map((t) => (
          <div key={t.name} className="rounded-lg border-2 border-ink bg-surface-container-lowest p-5 neo">
            <div className="mb-3 flex items-center justify-between">
              <div className="flex gap-0.5 text-tertiary">
                {Array.from({ length: t.rating }).map((_, i) => (
                  <Star key={i} size={15} weight="fill" />
                ))}
              </div>
              <Badge tone="info">Verified</Badge>
            </div>
            <q className="text-[13px] leading-relaxed text-on-surface not-italic">{t.quote}</q>
            <p className="mt-3 font-display text-[12px] font-bold">— {t.name}</p>
          </div>
        ))}
      </div>
    </section>
  );
}
