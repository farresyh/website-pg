/**
 * Loading-skeleton primitive (ADR-071 PR1a). A filled paper block with
 * a sweeping highlight — see `.neo-skeleton` in globals.css. Compose it
 * with Tailwind utilities for size/shape; add `border-2 border-ink` for
 * card-shaped placeholders so they match the real neo-brutalist cards.
 *
 *   <Skeleton className="h-8 w-40" />
 *   <Skeleton className="h-40 border-2 border-ink rounded-lg" />
 */
export default function Skeleton({ className = "" }: { className?: string }) {
  return <div aria-hidden="true" className={`neo-skeleton ${className}`} />;
}

/** A card-shaped skeleton — the neo-brutalist border + radius baked in. */
export function SkeletonCard({ className = "" }: { className?: string }) {
  return <div aria-hidden="true" className={`neo-skeleton rounded-lg border-2 border-ink ${className}`} />;
}

/** A run of text-line skeletons; the last line is shortened. */
export function SkeletonLines({ lines = 3, className = "" }: { lines?: number; className?: string }) {
  return (
    <div className={`flex flex-col gap-2 ${className}`}>
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton key={i} className={`h-3.5 ${i === lines - 1 ? "w-2/3" : "w-full"}`} />
      ))}
    </div>
  );
}
