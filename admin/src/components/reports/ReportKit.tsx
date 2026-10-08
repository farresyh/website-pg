"use client";

/**
 * ADR-104 R5/R13/R18 — the shared pieces every Reports tab is built from,
 * on the artifact's own tokens (surface/border/ink, chart-*). Rendering
 * only: every figure comes from the backend as-is.
 */

import { useState, type ReactNode } from "react";
import type * as React from "react";
import { Button } from "@/components/ui/button";
import type { PercentChange, PointsChange } from "@/lib/reports";

export function ReportCard({
  title,
  subtitle,
  actions,
  children,
  className = "",
  flush = false,
}: {
  title?: ReactNode;
  subtitle?: ReactNode;
  actions?: ReactNode;
  children: ReactNode;
  className?: string;
  /** No body padding, for a table that runs edge to edge. */
  flush?: boolean;
}) {
  return (
    <section className={`overflow-hidden rounded-lg border border-border bg-surface shadow-xs ${className}`}>
      {(title || actions) && (
        <header className={`flex flex-wrap items-center justify-between gap-3 ${flush ? "px-4 pt-4 pb-3" : "px-5 pt-5"}`}>
          {title && (
            <div>
              <h2 className="text-section-title font-semibold text-ink">{title}</h2>
              {subtitle && <p className="mt-0.5 text-theme-xs text-ink-muted">{subtitle}</p>}
            </div>
          )}
          {actions}
        </header>
      )}
      <div className={flush ? "" : "p-5"}>{children}</div>
    </section>
  );
}

/**
 * R13 — margin moves in percentage points; an empty previous period shows
 * "No data", never "+∞%". Neutral ink: up is not always good news.
 */
function ChangeLine({ change, previousLabel }: { change: PercentChange | PointsChange; previousLabel?: string }) {
  let text: string;
  if (change.direction === "new") {
    text = "— No data";
  } else if (change.direction === "flat") {
    text = "No change";
  } else {
    const arrow = change.direction === "up" ? "▲" : "▼";
    text = "points" in change ? `${arrow} ${change.points?.toFixed(2)} pts` : `${arrow} ${change.pct?.toFixed(1)}%`;
  }

  return (
    <p className="mt-1 text-theme-xs text-ink-muted">
      <span className="font-medium tabular-nums text-ink">{text}</span>
      {previousLabel && <span> vs {previousLabel}</span>}
    </p>
  );
}

export function KpiCard({
  label,
  value,
  sub,
  change,
  previousLabel,
  note,
  onClick,
  lead = false,
}: {
  label: string;
  value: string;
  /** The mockup's lead cards (Paid sales, Owner profit) carry the 30px value; the rest 22px. */
  lead?: boolean;
  sub?: string;
  change?: PercentChange | PointsChange;
  previousLabel?: string;
  /** R13 — e.g. "Profit is recognised on delivery" when the range includes today. */
  note?: string;
  /** R5 — clickable cards open their tab. */
  onClick?: () => void;
}) {
  const body = (
    <>
      <p className="text-theme-xs font-medium text-ink-muted">{label}</p>
      <p
        className={`mt-1 whitespace-nowrap font-semibold tabular-nums tracking-tight text-ink ${
          lead ? "text-[26px] leading-8 2xl:text-metric-lg" : "text-[22px] leading-8"
        }`}
      >
        {value}
      </p>
      {sub && <p className="mt-0.5 text-theme-xs text-ink-muted">{sub}</p>}
      {change && <ChangeLine change={change} previousLabel={previousLabel} />}
      {note && <p className="mt-1 text-theme-xs text-ink-muted">{note}</p>}
    </>
  );

  const base = "flex min-w-0 flex-col justify-start rounded-lg border border-border bg-surface p-4 text-left shadow-xs";
  return onClick ? (
    <button
      type="button"
      onClick={onClick}
      className={`${base} transition-shadow hover:border-border-strong hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring`}
    >
      {body}
    </button>
  ) : (
    <div className={base}>{body}</div>
  );
}

export interface ReportColumn<T> {
  key: string;
  header: string;
  align?: "left" | "right";
  render: (row: T) => ReactNode;
  /** Total-row cell; the row shows when any column sets one. */
  total?: ReactNode;
}

/** R18 — a Total row and 31 rows a page, so a long range never renders a 4,000px table. */
export function ReportTable<T>({
  columns,
  rows,
  rowKey,
  pageSize = 31,
  empty = "No paid orders in this range yet.",
}: {
  columns: ReportColumn<T>[];
  rows: T[];
  rowKey: (row: T) => string | number;
  pageSize?: number;
  empty?: string;
}) {
  const [page, setPage] = useState(0);
  const lastPage = Math.max(0, Math.ceil(rows.length / pageSize) - 1);
  const current = Math.min(page, lastPage);
  const visible = rows.slice(current * pageSize, (current + 1) * pageSize);
  const hasTotal = columns.some((c) => c.total !== undefined);
  const alignClass = (c: ReportColumn<T>) => (c.align === "right" ? "text-right" : "text-left");

  if (rows.length === 0) {
    return <p className="p-6 text-center text-sm text-ink-muted">{empty}</p>;
  }

  return (
    <div>
      <div className="max-w-full overflow-x-auto">
        <table className="w-full text-theme-sm">
          <thead>
            <tr className="border-b border-border bg-subtle text-theme-xs text-ink-muted">
              {columns.map((c) => (
                <th key={c.key} className={`whitespace-nowrap px-4 py-2.5 font-medium ${alignClass(c)}`}>
                  {c.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {visible.map((row) => (
              <tr key={rowKey(row)} className="border-b border-border last:border-0">
                {columns.map((c) => (
                  <td key={c.key} className={`whitespace-nowrap px-4 py-3 tabular-nums text-ink ${alignClass(c)}`}>
                    {c.render(row)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
          {hasTotal && (
            <tfoot>
              <tr className="border-t border-border-strong bg-subtle font-semibold">
                {columns.map((c) => (
                  <td key={c.key} className={`whitespace-nowrap px-4 py-3 tabular-nums text-ink ${alignClass(c)}`}>
                    {c.total}
                  </td>
                ))}
              </tr>
            </tfoot>
          )}
        </table>
      </div>
      {lastPage > 0 && (
        <div className="flex items-center justify-between border-t border-border px-4 py-3 text-theme-xs text-ink-muted">
          <span>
            Rows {current * pageSize + 1}–{Math.min(rows.length, (current + 1) * pageSize)} of {rows.length}
          </span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={current === 0} onClick={() => setPage(current - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={current === lastPage} onClick={() => setPage(current + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

/** R5 — a game's own icon, or its 2-letter initials when there is none. */
export function GameIcon({ name, src, size = 28 }: { name: string; src?: string | null; size?: number }) {
  const words = name.trim().split(/\s+/);
  const initials = (words.length > 1 ? words[0][0] + words[1][0] : name.slice(0, 2)).toUpperCase();
  return src ? (
    // eslint-disable-next-line @next/next/no-img-element -- gallery URLs are already WebP on R2 (ADR-095)
    <img src={src} alt="" width={size} height={size} className="shrink-0 rounded-sm object-cover" />
  ) : (
    <span
      aria-hidden
      className="inline-flex shrink-0 items-center justify-center rounded-sm bg-subtle text-[11px] font-semibold text-ink-muted"
      style={{ width: size, height: size }}
    >
      {initials}
    </span>
  );
}

/** The mockup's segmented toggle (Revenue & profit / Orders, Daily / Weekly / Monthly). */
export function SegmentedControl<T extends string>({
  options,
  value,
  onChange,
  label,
}: {
  options: { value: T; label: string }[];
  value: T;
  onChange: (value: T) => void;
  label: string;
}) {
  return (
    <div role="radiogroup" aria-label={label} className="inline-flex rounded-md bg-subtle p-0.5">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          role="radio"
          aria-checked={value === o.value}
          onClick={() => onChange(o.value)}
          className={`rounded-[5px] px-2.5 py-1 text-theme-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-focus-ring ${
            value === o.value ? "bg-surface text-ink shadow-xs" : "text-ink-muted hover:text-ink"
          }`}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

/** Header / filter-row button in the mockup's style: 36px, surface ground, control border. */
export function ToolbarButton({
  children,
  className = "",
  dashed = false,
  active = false,
  ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & { dashed?: boolean; active?: boolean }) {
  const tone = active
    ? "border-cyan-600 bg-cyan-50 text-cyan-ink"
    : dashed
      ? "border-dashed border-border-control bg-transparent text-ink-muted hover:text-ink"
      : "border-border-strong bg-surface text-ink hover:bg-subtle";
  return (
    <button
      type="button"
      className={`inline-flex h-9 items-center gap-2 rounded-md border px-3 text-[13px] font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring disabled:cursor-not-allowed disabled:opacity-50 ${tone} ${className}`}
      {...props}
    >
      {children}
    </button>
  );
}
