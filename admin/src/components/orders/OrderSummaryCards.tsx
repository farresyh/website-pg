/**
 * ADR-092: six always-visible KPI cards above the existing STATUS_FILTERS
 * pills — a second, larger-target entry point onto the exact same
 * `status` filter mechanism, not a parallel filtering system. Clicking a
 * card applies the same filter value its matching pill already sets.
 * "Awaiting Payment" deliberately stays a pill only (ADR-092 decision 2 —
 * a payment-side visibility gap, distinct from "delivery needs a
 * decision").
 */
import { Clock } from "@primeicons/react/clock";
import type { OrderStatusFilter, OrderSummary } from "@/lib/orders";

const CARDS: { value: OrderStatusFilter; label: string; key: keyof OrderSummary }[] = [
  { value: "need_action", label: "Need Action", key: "need_action" },
  { value: "needs_review", label: "Needs Review", key: "needs_review" },
  { value: "processing", label: "Processing", key: "processing" },
  { value: "completed", label: "Completed", key: "completed" },
  { value: "today", label: "Today", key: "today" },
  { value: "all", label: "All Orders", key: "all" },
];

export default function OrderSummaryCards({
  summary,
  activeStatus,
  onSelect,
}: {
  summary: OrderSummary | null;
  activeStatus: OrderStatusFilter;
  onSelect: (status: OrderStatusFilter) => void;
}) {
  return (
    <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      {CARDS.map((card) => {
        const active = activeStatus === card.value;
        // ADR-104: "Need Action" is a permanent urgency marker (purple),
        // independent of whether it's the currently-selected filter — the
        // artifact's own KpiCards mockup shows it purple regardless of
        // selection. Every other card is neutral by default and only
        // picks up the cyan "act here"/selected treatment when it happens
        // to be the active filter — the same behavior this already had.
        const isNeedAction = card.value === "need_action";
        return (
          <button
            key={card.value}
            onClick={() => onSelect(card.value)}
            className={`relative rounded-2xl border p-4 text-left transition-colors ${
              isNeedAction
                ? "border-purple-200 bg-purple-50"
                : active
                  ? "border-cyan-600 bg-cyan-50 dark:border-cyan-600"
                  : "border-gray-200 bg-surface hover:border-gray-300 dark:border-gray-800 dark:hover:border-gray-700"
            }`}
          >
            {isNeedAction && <span aria-hidden="true" className="absolute right-4 top-4 h-2 w-2 rounded-full bg-purple-600" />}
            {card.value === "needs_review" && <Clock className="absolute right-4 top-4 h-3.5 w-3.5 text-ink-muted" />}
            <p className={`text-overline font-medium uppercase tracking-wide ${isNeedAction ? "text-purple-ink" : "text-ink-muted"}`}>{card.label}</p>
            <p className={`mt-1 text-metric-lg font-semibold ${isNeedAction ? "text-purple-ink" : "text-ink"}`}>
              {summary ? summary[card.key] : "—"}
            </p>
          </button>
        );
      })}
    </div>
  );
}
