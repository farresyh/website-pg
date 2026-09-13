/**
 * ADR-092: six always-visible KPI cards above the existing STATUS_FILTERS
 * pills — a second, larger-target entry point onto the exact same
 * `status` filter mechanism, not a parallel filtering system. Clicking a
 * card applies the same filter value its matching pill already sets.
 * "Awaiting Payment" deliberately stays a pill only (ADR-092 decision 2 —
 * a payment-side visibility gap, distinct from "delivery needs a
 * decision").
 */
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
        return (
          <button
            key={card.value}
            onClick={() => onSelect(card.value)}
            className={`rounded-2xl border p-4 text-left transition-colors ${
              active
                ? "border-brand-500 bg-brand-50 dark:border-brand-500 dark:bg-brand-500/10"
                : "border-gray-200 bg-white hover:border-gray-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700"
            }`}
          >
            <p className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{card.label}</p>
            <p className="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">
              {summary ? summary[card.key] : "—"}
            </p>
          </button>
        );
      })}
    </div>
  );
}
