import type { ReactNode } from "react";
import Skeleton, { SkeletonCard, SkeletonLines } from "@/components/ui/Skeleton";
import SkeletonShell from "@/components/skeletons/SkeletonShell";

/**
 * Route skeletons for the "title + lede + body" pages. Each export
 * mirrors its page's container width and body shape so content lands
 * without a layout shift. `TitledBody` is shared, not exported per page.
 */
function TitledBody({ width, centered = false, children }: { width: string; centered?: boolean; children: ReactNode }) {
  const align = centered ? "mx-auto" : "";
  return (
    <div className={`mx-auto ${width} px-4 py-12 lg:py-16`}>
      <Skeleton className={`h-9 w-64 ${align}`} />
      <Skeleton className={`mt-3 h-4 w-full max-w-[62ch] ${align}`} />
      <Skeleton className={`mt-2 h-4 w-2/3 max-w-[40ch] ${align}`} />
      <div className="mt-8">{children}</div>
    </div>
  );
}

/** `/price-list` — the tier table, then the WhatsApp CTA card. */
export function PriceListSkeleton() {
  return (
    <SkeletonShell>
      <TitledBody width="max-w-[900px]">
        <SkeletonCard className="h-80" />
        <SkeletonCard className="mt-8 h-24" />
      </TitledBody>
    </SkeletonShell>
  );
}

/** `/check-id` — the centred game-family card grid (FamilyCards). */
export function CheckIdSkeleton() {
  return (
    <SkeletonShell>
      <TitledBody width="max-w-[1200px]" centered>
        <div className="flex flex-wrap justify-center gap-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <SkeletonCard key={i} className="aspect-16/10 w-[calc(50%-0.5rem)] lg:w-[calc(25%-0.75rem)]" />
          ))}
        </div>
      </TitledBody>
    </SkeletonShell>
  );
}

/** `/check-id/[slug]` — family tiles, then the checker form card. */
export function CheckIdGameSkeleton() {
  return (
    <SkeletonShell>
      <TitledBody width="max-w-[760px]">
        <div className="grid grid-cols-3 gap-2 sm:grid-cols-5">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-20 rounded-md border-2 border-ink" />
          ))}
        </div>
        <SkeletonCard className="mt-6 h-56" />
      </TitledBody>
    </SkeletonShell>
  );
}

/** `/about-us`, `/terms`, `/privacy`, `/refund-policy` — one prose card (LegalPageContent). */
export function LegalPageSkeleton() {
  return (
    <SkeletonShell>
      <div className="mx-auto max-w-[760px] px-4 py-12 lg:py-16">
        <Skeleton className="h-9 w-56" />
        <div className="mt-6 rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo lg:p-8">
          <SkeletonLines lines={4} />
          <SkeletonLines lines={5} className="mt-6" />
        </div>
      </div>
    </SkeletonShell>
  );
}

/**
 * `/membership`'s guest email step (the narrow 420px form). Body only —
 * the page's own `<Suspense>` fallback reuses it inside its `<main>`.
 */
export function MembershipFormSkeleton() {
  return (
    <div className="mx-auto max-w-[420px] px-4 py-10 lg:py-16">
      <Skeleton className="mb-2 h-9 w-48" />
      <Skeleton className="mb-6 h-4 w-full" />
      <Skeleton className="h-11 w-full rounded-md border-2 border-ink" />
      <Skeleton className="mt-3 h-11 w-full rounded-md border-2 border-ink" />
    </div>
  );
}

export function MembershipSkeleton() {
  return (
    <SkeletonShell>
      <MembershipFormSkeleton />
    </SkeletonShell>
  );
}
