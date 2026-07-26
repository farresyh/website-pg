import type { ReactNode } from "react";

interface StepCardProps {
  number: number;
  title: string;
  locked: boolean;
  lockHint?: string;
  children: ReactNode;
}

/**
 * `inert` (not just opacity + pointer-events:none, which the original
 * reference used) so a locked step is genuinely unreachable by
 * keyboard/screen-reader, not just visually dimmed.
 */
export default function StepCard({ number, title, locked, lockHint, children }: StepCardProps) {
  return (
    <div
      className={`rounded-2xl border border-border bg-surface p-5 transition-opacity duration-200 lg:p-6 ${locked ? "opacity-45" : ""}`}
      aria-disabled={locked}
      inert={locked ? true : undefined}
    >
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2.5">
          <span className="flex h-6 w-6 items-center justify-center rounded-full bg-surface-2 font-mono text-xs font-bold text-brand-light">
            {number}
          </span>
          <h2 className="text-base font-bold">{title}</h2>
        </div>
        {locked && lockHint && <span className="text-xs text-text-muted italic">{lockHint}</span>}
      </div>
      {children}
    </div>
  );
}
