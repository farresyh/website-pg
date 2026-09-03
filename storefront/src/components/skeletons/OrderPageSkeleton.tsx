import Skeleton, { SkeletonCard } from "@/components/ui/Skeleton";
import SkeletonShell from "@/components/skeletons/SkeletonShell";

/**
 * Matches `/order/[slug]`'s real composition (ADR-071 PR1a): breadcrumb
 * → product header card → stepper → three step cards, with the summary
 * sidebar alongside from `lg`.
 */
export default function OrderPageSkeleton() {
  return (
    <SkeletonShell>
      <div className="mx-auto max-w-[1200px] px-4 pt-5">
        <Skeleton className="h-3 w-48" />
      </div>

      <div className="mx-auto max-w-[1200px] px-4 pb-10">
        {/* product header card */}
        <div className="mb-6 flex items-center gap-5 rounded-lg border-2 border-ink bg-surface-container-lowest p-5 neo">
          <Skeleton className="h-16 w-16 shrink-0 rounded-md border-2 border-ink" />
          <div className="flex-1 space-y-2.5">
            <Skeleton className="h-6 w-2/3 max-w-[280px]" />
            <Skeleton className="h-4 w-40" />
          </div>
        </div>

        <div className="grid gap-5 lg:grid-cols-[1fr_360px] lg:items-start">
          <div className="flex flex-col gap-5">
            {/* stepper */}
            <div className="rounded-lg border-2 border-ink bg-surface-container-lowest p-4 neo">
              <div className="flex items-center gap-3">
                <Skeleton className="h-8 w-8 rounded-full border-2 border-ink" />
                <Skeleton className="h-0.5 flex-1" />
                <Skeleton className="h-8 w-8 rounded-full border-2 border-ink" />
                <Skeleton className="h-0.5 flex-1" />
                <Skeleton className="h-8 w-8 rounded-full border-2 border-ink" />
              </div>
              <Skeleton className="mt-3 h-3 w-40" />
            </div>

            {/* step cards */}
            {[0, 1, 2].map((i) => (
              <div key={i} className="rounded-lg border-2 border-ink bg-surface-container-lowest p-5 neo">
                <Skeleton className="mb-4 h-4 w-44" />
                {i === 1 ? (
                  <div className="grid grid-cols-2 gap-2.5">
                    {Array.from({ length: 6 }).map((_, j) => (
                      <SkeletonCard key={j} className="h-[74px]" />
                    ))}
                  </div>
                ) : (
                  <div className="space-y-3">
                    <Skeleton className="h-11 w-full rounded-md border-2 border-ink" />
                    <Skeleton className="h-11 w-full rounded-md border-2 border-ink" />
                  </div>
                )}
              </div>
            ))}
          </div>

          {/* summary sidebar */}
          <div className="hidden lg:block">
            <div className="rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo-lg">
              <Skeleton className="mb-5 h-5 w-36" />
              <div className="space-y-3">
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-4/5" />
                <Skeleton className="h-4 w-3/5" />
              </div>
              <Skeleton className="mt-5 h-px w-full" />
              <Skeleton className="mt-5 h-8 w-32" />
              <Skeleton className="mt-4 h-11 w-full rounded-md border-2 border-ink" />
            </div>
          </div>
        </div>
      </div>
    </SkeletonShell>
  );
}
