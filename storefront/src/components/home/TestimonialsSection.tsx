import { Star, SealCheck } from "@phosphor-icons/react/dist/ssr";
import { TESTIMONIALS } from "@/lib/placeholder-data";

export default function TestimonialsSection() {
  return (
    <section className="mx-auto max-w-[1200px] px-4 py-10">
      <h2 className="font-display mb-5 text-[22px] tracking-wide">What Customers Say</h2>
      <div className="grid gap-3.5 lg:grid-cols-3">
        {TESTIMONIALS.map((t) => (
          <div key={t.name} className="rounded-xl border border-border bg-surface p-4.5">
            <div className="mb-2.5 flex gap-0.5 text-brand-light">
              {Array.from({ length: t.rating }).map((_, i) => (
                <Star key={i} size={14} weight="fill" />
              ))}
            </div>
            <div className="mb-2 flex items-center gap-2">
              <span className="text-[13.5px] font-bold">{t.name}</span>
              <span className="flex items-center gap-1 rounded-full bg-brand-dark px-2 py-0.5 text-[10px] font-semibold text-brand-light">
                <SealCheck size={11} weight="fill" />
                Verified
              </span>
            </div>
            <q className="text-[13px] leading-relaxed text-text-muted not-italic">{t.quote}</q>
          </div>
        ))}
      </div>
    </section>
  );
}
