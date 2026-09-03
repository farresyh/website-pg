import Skeleton, { SkeletonLines } from "@/components/ui/Skeleton";
import SkeletonShell from "@/components/skeletons/SkeletonShell";

/**
 * Matches `/order/status/[orderNumber]` (ADR-071 PR1a): the "Order
 * Status" heading, the reference + stage-tracker card, the detail
 * cards, and the support card alongside from `lg`.
 */
export default function OrderStatusSkeleton() {
  return (
    <SkeletonShell>
      <div className="mx-auto max-w-[1200px] px-4 py-8">
        <Skeleton className="mb-6 h-9 w-56" />

        <div className="grid gap-6 lg:grid-cols-[1fr_360px] lg:items-start">
          <div className="flex flex-col gap-4">
            <div className="rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="space-y-2">
                  <Skeleton className="h-3 w-40" />
                  <Skeleton className="h-4 w-48" />
                </div>
                <div className="flex gap-2">
                  <Skeleton className="h-5 w-20 rounded-sm border border-ink" />
                  <Skeleton className="h-5 w-24 rounded-sm border border-ink" />
                </div>
              </div>
              <Skeleton className="my-5 h-px w-full" />
              <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-4">
                {[0, 1, 2].map((i) => (
                  <div key={i} className="flex items-center gap-2.5 lg:flex-1">
                    <Skeleton className="h-8 w-8 shrink-0 rounded-full border-2 border-ink" />
                    <div className="flex-1 space-y-1.5">
                      <Skeleton className="h-3.5 w-28" />
                      <Skeleton className="h-3 w-16" />
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              {[0, 1].map((i) => (
                <div key={i} className="rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo">
                  <Skeleton className="mb-4 h-5 w-36" />
                  <div className="space-y-3">
                    <Skeleton className="h-4 w-full" />
                    <Skeleton className="h-4 w-4/5" />
                    <Skeleton className="h-4 w-3/5" />
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="hidden lg:block">
            <div className="rounded-lg border-2 border-ink bg-primary-fixed p-6 neo">
              <Skeleton className="mb-3.5 h-5 w-48" />
              <SkeletonLines lines={3} />
              <Skeleton className="mt-4 h-11 w-full rounded-md border-2 border-ink" />
            </div>
          </div>
        </div>
      </div>
    </SkeletonShell>
  );
}
