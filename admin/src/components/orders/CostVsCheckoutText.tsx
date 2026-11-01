import type { CostVsCheckoutFigure } from "@/lib/delivery-log-entries";

/**
 * ADR-106 2026-11-02 addendum: a delivered unit's cost against its checkout
 * cost. Red when it cost more, green when less. "catalog" marks a figure
 * resting on the catalog cost, not the supplier's real charge.
 */
export default function CostVsCheckoutText({ figure }: { figure: CostVsCheckoutFigure | null }) {
  if (figure === null) return <span className="text-ink-subtle">—</span>;

  const tone =
    figure.sen > 0
      ? "font-medium text-error-600 dark:text-error-400"
      : figure.sen < 0
        ? "font-medium text-success-600 dark:text-success-400"
        : "text-ink-muted";

  return (
    <span className={tone}>
      {figure.sen > 0 ? "+" : figure.sen < 0 ? "−" : ""}RM {(Math.abs(figure.sen) / 100).toFixed(2)}
      {figure.basis !== "real" && <span className="ms-1 text-theme-xs font-normal text-ink-subtle">(catalog)</span>}
    </span>
  );
}
