import Skeleton, { SkeletonCard } from "@/components/ui/Skeleton";
import SkeletonShell from "@/components/skeletons/SkeletonShell";

/**
 * Matches `/`'s above-the-fold composition: hero slider + Quick Top-Up
 * card, the trust line, then the Popular Picks product grid. Below the
 * fold is left out on purpose — it is off-screen when the fallback shows.
 */
export default function HomeSkeleton() {
  return (
    <SkeletonShell>
      <section className="mx-auto max-w-[1200px] px-4 py-8 lg:py-10">
        <div className="grid gap-5 lg:grid-cols-[2fr_1fr] lg:items-stretch">
          <SkeletonCard className="aspect-[2/1]" />
          <SkeletonCard className="min-h-[220px]" />
        </div>
        <div className="mt-6 flex flex-wrap gap-x-6 gap-y-2">
          <Skeleton className="h-4 w-36" />
          <Skeleton className="h-4 w-44" />
          <Skeleton className="h-4 w-40" />
        </div>
      </section>

      <section className="mx-auto max-w-[1200px] px-4 py-9 lg:py-12">
        <Skeleton className="mb-3 h-7 w-44" />
        <Skeleton className="mb-6 h-4 w-64" />
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="overflow-hidden rounded-lg border-2 border-ink bg-surface-container-lowest neo">
              <Skeleton className="aspect-16/10 border-b-2 border-ink" />
              <div className="flex flex-col gap-2 p-3.5">
                <Skeleton className="h-4 w-3/4" />
                <Skeleton className="h-3 w-1/2" />
                <Skeleton className="mt-2 h-4 w-2/3" />
              </div>
            </div>
          ))}
        </div>
      </section>
    </SkeletonShell>
  );
}
