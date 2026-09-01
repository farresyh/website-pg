import type { ReactNode } from "react";

/**
 * ADR-064 primitive. Small high-contrast status label — 1px ink border,
 * solid accent ground, squared. `tone` picks the accent block; `pill`
 * switches to the rounded-full promo-chip shape (the one exception to
 * this world's squared geometry).
 */
type Tone = "neutral" | "primary" | "success" | "warning" | "danger" | "info" | "new";

const toneClasses: Record<Tone, string> = {
  neutral: "bg-surface-container text-on-surface",
  primary: "bg-primary-fixed text-on-primary-fixed",
  success: "bg-success text-on-success",
  warning: "bg-warning text-on-warning",
  danger: "bg-danger-container text-on-danger-container",
  info: "bg-secondary-container text-on-secondary-container",
  new: "bg-tertiary text-on-tertiary",
};

export default function Badge({
  children,
  tone = "neutral",
  pill = false,
  className = "",
}: {
  children: ReactNode;
  tone?: Tone;
  pill?: boolean;
  className?: string;
}) {
  return (
    <span
      className={`inline-flex items-center gap-1 border border-ink px-2 py-0.5 font-display text-[11px] font-bold uppercase tracking-wide ${
        pill ? "rounded-full" : "rounded-sm"
      } ${toneClasses[tone]} ${className}`}
    >
      {children}
    </span>
  );
}
