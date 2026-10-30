import type { ReactNode } from "react";

interface StepCardProps {
  number: number;
  title: string;
  locked: boolean;
  lockHint?: string;
  /** A locked card that can still be looked at and tapped (the package list before the ID is verified). */
  browsable?: boolean;
  children: ReactNode;
}

/**
 * ADR-064: Stitch's numbered-section card — a floating label notched
 * into the top border. `inert` (not just opacity + pointer-events) so a
 * locked step is genuinely unreachable by keyboard / screen-reader.
 * `browsable` keeps a locked card readable and tappable (still shows the hint).
 */
export default function StepCard({ number, title, locked, lockHint, browsable = false, children }: StepCardProps) {
  const inactive = locked && !browsable;
  return (
    <div
      className={`relative rounded-lg border-2 border-ink bg-surface-container-lowest p-5 pt-7 neo transition-opacity duration-200 lg:p-6 lg:pt-8 ${
        inactive ? "opacity-45" : ""
      }`}
      aria-disabled={inactive}
      inert={inactive ? true : undefined}
    >
      <div className="absolute -top-3.5 left-5 flex items-center gap-2 bg-surface-container-lowest px-2">
        <span className="flex h-6 w-6 items-center justify-center rounded-sm border-2 border-ink bg-primary font-mono text-[11px] font-bold text-on-primary">
          {number}
        </span>
        <h2 className="font-display text-[13px] font-bold uppercase tracking-wide">{title}</h2>
      </div>
      {locked && lockHint && (
        <p className="mb-3 text-xs italic text-on-surface-variant">{lockHint}</p>
      )}
      {children}
    </div>
  );
}
