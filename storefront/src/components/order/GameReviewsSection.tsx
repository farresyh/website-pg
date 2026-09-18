import { Star, SealCheck } from "@phosphor-icons/react/dist/ssr";
import Badge from "@/components/ui/Badge";
import type { GameReviewsResult } from "@/lib/review";

interface GameReviewsSectionProps {
  gameName: string;
  data: GameReviewsResult;
}

export default function GameReviewsSection({ gameName, data }: GameReviewsSectionProps) {
  // Decision Q4: Auto-hide section if there are no approved reviews for this game in this store
  if (!data || data.review_count === 0 || data.reviews.length === 0) {
    return null;
  }

  return (
    <section className="border-y-2 border-ink bg-surface-container py-9 lg:py-12">
      <div className="mx-auto max-w-[1200px] px-4">
        {/* Header with Title and Aggregate Score Badge */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between border-b-2 border-ink/15 pb-5">
          <div>
            <h2 className="font-display text-lg font-bold uppercase tracking-tight sm:text-xl text-on-surface">
              Customer Reviews for {gameName}
            </h2>
            <p className="text-xs text-on-surface-variant font-medium mt-1">
              Real feedback from verified buyers in this store
            </p>
          </div>

          {/* Aggregate Rating Score Badge */}
          <div className="flex items-center gap-3 self-start sm:self-auto rounded-lg border-2 border-ink bg-surface-container-lowest px-3.5 py-2 neo-sm">
            <span className="font-display text-xl font-bold text-on-surface leading-none">
              {data.average_rating.toFixed(1)}
            </span>
            <div className="flex flex-col">
              <div className="flex gap-0.5 text-tertiary">
                {Array.from({ length: 5 }).map((_, i) => (
                  <Star
                    key={i}
                    size={13}
                    weight={i < Math.round(data.average_rating) ? "fill" : "regular"}
                  />
                ))}
              </div>
              <span className="text-[10px] font-bold text-on-surface-variant mt-0.5">
                {data.review_count} {data.review_count === 1 ? "verified review" : "verified reviews"}
              </span>
            </div>
          </div>
        </div>

        {/* 3 Latest Review Cards: Desktop 3 columns, Mobile vertical stack */}
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 mt-6">
          {data.reviews.map((r) => (
            <div
              key={r.id}
              className="flex flex-col justify-between rounded-lg border-2 border-ink bg-surface-container-lowest p-5 neo transition-transform hover:-translate-y-0.5"
            >
              <div>
                <div className="flex items-center justify-between gap-2 mb-3">
                  <div className="flex shrink-0 gap-0.5 text-tertiary">
                    {Array.from({ length: Math.min(Math.max(r.rating, 1), 5) }).map((_, i) => (
                      <Star key={i} size={15} weight="fill" />
                    ))}
                  </div>
                  {r.package_name && (
                    <span className="rounded border border-ink/20 bg-surface-container px-2 py-0.5 text-[10px] font-bold text-on-surface-variant max-w-[180px] truncate">
                      {r.package_name}
                    </span>
                  )}
                </div>
                <q className="text-[13px] leading-relaxed text-on-surface not-italic line-clamp-3 block">
                  {r.comment}
                </q>
              </div>

              <div className="mt-4 pt-3 border-t border-ink/10 flex items-center justify-between gap-2">
                <span className="font-display text-[12px] font-bold text-on-surface truncate">
                  - {r.name}
                </span>
                <Badge tone="info">
                  <span className="flex items-center gap-1">
                    <SealCheck size={12} weight="bold" /> Verified Buyer
                  </span>
                </Badge>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
