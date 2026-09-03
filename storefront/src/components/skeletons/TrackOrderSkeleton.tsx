import Skeleton from "@/components/ui/Skeleton";
import SkeletonShell from "@/components/skeletons/SkeletonShell";

/** Matches `/track-order`'s narrow lookup form (ADR-071 PR1a). */
export default function TrackOrderSkeleton() {
  return (
    <SkeletonShell>
      <div className="mx-auto max-w-[560px] px-4 py-10">
        <Skeleton className="mx-auto mb-2 h-8 w-48" />
        <Skeleton className="mx-auto mb-8 h-4 w-72" />
        <div className="rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo">
          <Skeleton className="mb-2 h-3.5 w-32" />
          <Skeleton className="mb-4 h-11 w-full rounded-md border-2 border-ink" />
          <Skeleton className="h-11 w-full rounded-md border-2 border-ink" />
        </div>
      </div>
    </SkeletonShell>
  );
}
