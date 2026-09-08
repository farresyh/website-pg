import { Star, SealCheck } from "@phosphor-icons/react/dist/ssr";
import SectionHeading from "@/components/home/SectionHeading";
import Badge from "@/components/ui/Badge";
import { TESTIMONIALS } from "@/lib/placeholder-data";
import type { PublicReview } from "@/lib/review";

interface TestimonialsSectionProps {
  reviews?: PublicReview[];
}

export default function TestimonialsSection({ reviews = [] }: TestimonialsSectionProps) {
  // Use real approved reviews if available, otherwise fall back to honest placeholders
  const baseItems =
    reviews.length > 0
      ? reviews.map((r) => ({
          name: r.name,
          rating: r.rating,
          quote: r.comment,
          gameName: r.game_name ?? null,
          packageName: r.package_name ?? null,
        }))
      : TESTIMONIALS.map((t) => ({
          name: t.name,
          rating: t.rating,
          quote: t.quote,
          gameName: null,
          packageName: null,
        }));

  // Duplicate the array for a seamless, continuous infinite marquee loop
  const loopItems = [...baseItems, ...baseItems];

  return (
    <section className="overflow-hidden py-9 lg:py-12">
      <div className="mx-auto max-w-[1200px] px-4">
        <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between mb-2">
          <SectionHeading title="What Customers Say" />
          <p className="text-xs text-on-surface-variant font-medium -mt-4 mb-6 sm:mt-0 sm:mb-6">
            Real feedback from verified buyers • Hover to pause
          </p>
        </div>
      </div>

      <div className="relative w-full overflow-hidden py-3">
        {/* Subtle edge fades for modern visual finish */}
        <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-8 md:w-16 bg-gradient-to-r from-surface to-transparent" />
        <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-8 md:w-16 bg-gradient-to-l from-surface to-transparent" />

        <div className="animate-marquee flex gap-4 pl-4">
          {loopItems.map((t, idx) => (
            <div
              key={`${t.name}-${idx}`}
              className="flex w-[320px] sm:w-[380px] shrink-0 flex-col justify-between rounded-lg border-2 border-ink bg-surface-container-lowest p-5 neo transition-transform hover:-translate-y-0.5"
            >
              <div>
                <div className="mb-3 flex items-center justify-between gap-2">
                  <div className="flex shrink-0 gap-0.5 text-tertiary">
                    {Array.from({ length: Math.min(Math.max(t.rating, 1), 5) }).map((_, i) => (
                      <Star key={i} size={15} weight="fill" />
                    ))}
                  </div>
                  <div className="flex items-center gap-1.5 flex-wrap justify-end">
                    {(t.gameName || t.packageName) && (
                      <span className="rounded border border-ink/20 bg-surface-container px-2 py-0.5 text-[10px] font-bold text-on-surface-variant max-w-[200px] truncate">
                        {t.gameName}
                        {t.gameName && t.packageName && " • "}
                        {t.packageName}
                      </span>
                    )}
                    <Badge tone="info">
                      <span className="flex items-center gap-1">
                        <SealCheck size={12} weight="bold" /> Verified
                      </span>
                    </Badge>
                  </div>
                </div>
                <q className="text-[13px] leading-relaxed text-on-surface not-italic line-clamp-3">
                  {t.quote}
                </q>
              </div>
              <p className="mt-4 font-display text-[12px] font-bold text-on-surface">
                — {t.name}
              </p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
