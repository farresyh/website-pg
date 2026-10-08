"use client";

/**
 * Orders tab — delivery health, in the artifact mockup's shape: one
 * stacked share bar (delivered / failed / in progress or review), then a
 * row per status with its badge, a track and count + share. Status
 * colour is reserved for delivery health (artifact `chart-positive` /
 * `chart-negative` / status tokens) and always travels with its label.
 *
 * ADR-104 R1 names this component for Recharts; it is plain HTML
 * instead, because the mockup's form is a share bar and labelled bar
 * rows, which need no axes or scales. Partially delivered stays a row
 * (ADR-104 review: the mockup omits it, the system has it).
 */

import type { ReportOrderStatusFunnel } from "@/lib/reports";

type StatusKey = keyof ReportOrderStatusFunnel["by_status"];

const ROWS: { key: StatusKey; label: string; badge: string; bar: string }[] = [
  { key: "delivered", label: "Delivered", badge: "bg-success-surface text-success-ink", bar: "bg-chart-positive" },
  { key: "processing", label: "Processing", badge: "bg-warning-surface text-warning-ink", bar: "bg-warning-ink" },
  { key: "pending", label: "Pending supplier", badge: "bg-warning-surface text-warning-ink", bar: "bg-warning-ink" },
  { key: "not_started", label: "Not started", badge: "bg-neutral-surface text-neutral-ink", bar: "bg-neutral-ink" },
  { key: "needs_review", label: "Needs review", badge: "bg-review-surface text-review-ink", bar: "bg-review-ink" },
  { key: "partially_delivered", label: "Partially delivered", badge: "bg-review-surface text-review-ink", bar: "bg-review-ink" },
  { key: "failed", label: "Failed", badge: "bg-danger-surface text-danger-ink", bar: "bg-chart-negative" },
];

const pct = (n: number, total: number) => (total > 0 ? (n / total) * 100 : 0);

export function OrderStatusFunnelChart({ funnel }: { funnel: ReportOrderStatusFunnel }) {
  const { total, by_status: s } = funnel;
  if (total === 0) {
    return <p className="py-8 text-center text-sm text-ink-muted">No paid orders in this range yet.</p>;
  }

  const other = total - s.delivered - s.failed;
  const share = [
    { label: "Delivered", count: s.delivered, dot: "bg-chart-positive" },
    { label: "Failed", count: s.failed, dot: "bg-chart-negative" },
    { label: "In progress or review", count: other, dot: "bg-warning-ink" },
  ];

  return (
    <div>
      <div
        className="flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full bg-chart-grid"
        role="img"
        aria-label={share.map((x) => `${x.label} ${pct(x.count, total).toFixed(0)}%`).join(", ")}
      >
        {share.map((x) =>
          x.count > 0 ? <span key={x.label} className={x.dot} style={{ width: `${pct(x.count, total)}%` }} /> : null,
        )}
      </div>
      <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-theme-xs text-ink-muted">
        {share.map((x) => (
          <span key={x.label} className="flex items-center gap-1.5">
            <span className={`size-2 rounded-full ${x.dot}`} />
            {x.label} <b className="font-semibold text-ink">{pct(x.count, total).toFixed(0)}%</b> · {x.count.toLocaleString()}{" "}
            {x.count === 1 ? "order" : "orders"}
          </span>
        ))}
      </p>

      <ul className="mt-4 divide-y divide-border">
        {ROWS.map((row) => {
          const count = s[row.key];
          const p = pct(count, total);
          return (
            <li key={row.key} className="grid grid-cols-[9.5rem_1fr_6rem] items-center gap-3 py-2.5 sm:grid-cols-[11rem_1fr_7rem]">
              <span className={`inline-flex w-fit items-center gap-1.5 rounded-sm px-2 py-0.5 text-theme-xs font-medium ${row.badge}`}>
                <span className="size-1.5 rounded-full bg-current" />
                {row.label}
              </span>
              <span className="h-2 overflow-hidden rounded-full bg-chart-grid">
                <span className={`block h-full rounded-full ${row.bar}`} style={{ width: `${p}%` }} />
              </span>
              <span className="text-right text-theme-sm tabular-nums">
                <b className="font-semibold text-ink">{count.toLocaleString()}</b>{" "}
                <span className="text-theme-xs text-ink-muted">{p.toFixed(1)}%</span>
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
