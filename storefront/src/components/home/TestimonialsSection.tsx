import { Star, SealCheck } from "@phosphor-icons/react/dist/ssr";
import SectionHeading from "@/components/home/SectionHeading";
import Badge from "@/components/ui/Badge";
import type { PublicReview } from "@/lib/review";

const MIN_CARDS_PER_COPY = 8;
/** Same pace as before (35s for a 12-card copy), whatever the copy length. */
const SECONDS_PER_CARD = 35 / 12;

interface TestimonialsSectionProps {
  reviews?: PublicReview[];
}

export default function TestimonialsSection({ reviews = [] }: TestimonialsSectionProps) {
  // Real approved reviews only — this brand has no verified feedback yet
  // renders nothing rather than fabricated placeholder testimonials.
  if (reviews.length === 0) {
    return null;
  }

  const baseItems = reviews.map((r) => ({
    name: r.name,
    rating: r.rating,
    quote: r.comment,
    gameName: r.game_name ?? null,
    packageName: r.package_name ?? null,
  }));

  // The keyframes slide the strip by -50%, so the loop is seamless only
  // when one half (a "copy") is wider than the viewport. Repeat the
  // reviews until a copy holds MIN_CARDS_PER_COPY cards (8 × 336px covers
  // a 2560px screen), then render that copy twice. Cards past the first
  // set are decorative repeats: hidden from screen readers, and dropped
  // under reduced motion (globals.css), where the strip wraps instead.
  const repeats = Math.ceil(MIN_CARDS_PER_COPY / baseItems.length);
  const copy = Array.from({ length: repeats }, () => baseItems).flat();
  const loopItems = [...copy, ...copy];

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

      <div className="relative w-full overflow-hidden py-3 pl-4">
        {/* Subtle edge fades for modern visual finish */}
        <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-8 md:w-16 bg-gradient-to-r from-surface to-transparent" />
        <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-8 md:w-16 bg-gradient-to-l from-surface to-transparent" />

        {/* Spacing is each card's own right padding, not `gap`, so -50%
            lands exactly on the start of the second copy (no jump); the
            left inset lives on the wrapper for the same reason. */}
        <div
          className="animate-marquee flex"
          style={{ animationDuration: `${copy.length * SECONDS_PER_CARD}s` }}
        >
          {loopItems.map((t, idx) => {
            const isRepeat = idx >= baseItems.length;
            return (
              <div
                key={idx}
                aria-hidden={isRepeat || undefined}
                data-marquee-repeat={isRepeat || undefined}
                className="shrink-0 pr-4"
              >
                <div
                  className="flex h-full w-[320px] sm:w-[380px] flex-col justify-between rounded-lg border-2 border-ink bg-surface-container-lowest p-5 neo transition-transform hover:-translate-y-0.5"
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
                    - {t.name}
                  </p>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}
