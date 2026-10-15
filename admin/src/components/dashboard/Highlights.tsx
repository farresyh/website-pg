"use client";

/**
 * ADR-104 2026-10-15 — three facts from the Dashboard's period
 * (`/reports/highlights`). A fact with no data is left out, and the card
 * renders nothing when all three are empty.
 */

import Link from "next/link";
import { formatRm } from "@/components/reports/format";
import type { ReportHighlights } from "@/lib/reports";

function Fact({ label, value, sub, tag }: { label: string; value: string; sub: React.ReactNode; tag?: string }) {
  return (
    <div className="min-w-0 px-5 pt-4 pb-5 [&+&]:border-t [&+&]:border-border md:[&+&]:border-t-0 md:[&+&]:border-l">
      <dt className="flex items-center gap-2 text-[13px] text-ink-muted">
        {label}
        {tag && <span className="inline-flex h-5 items-center rounded-sm bg-nude-100 px-1.5 text-[11px] font-semibold text-nude-ink">{tag}</span>}
      </dt>
      <dd className="m-0">
        <span className="mt-1.5 block text-xl font-semibold tracking-tight tabular-nums text-ink">{value}</span>
        <small className="mt-0.5 block truncate text-theme-xs text-ink-subtle">{sub}</small>
      </dd>
    </div>
  );
}

function day(date: string): string {
  return new Date(`${date}T00:00:00Z`).toLocaleDateString("en-MY", { weekday: "short", day: "numeric", month: "short", timeZone: "UTC" });
}

export function Highlights({ facts }: { facts: ReportHighlights }) {
  const { best_day: best, top_reseller: reseller, biggest_order: biggest } = facts;
  if (!best && !reseller && !biggest) return null;

  return (
    <section className="overflow-hidden rounded-lg border border-border bg-surface shadow-xs">
      <header className="px-5 pt-5">
        <h2 className="text-section-title font-semibold text-ink">Highlights</h2>
        <p className="mt-0.5 text-theme-xs text-ink-muted">Facts from this period. A fact with no data is left out.</p>
      </header>
      <dl className="m-0 mt-1 grid md:auto-cols-fr md:grid-flow-col">
        {best && (
          <Fact
            label="Best day"
            tag={best.is_all_time_record ? "All-time record" : undefined}
            value={formatRm(best.sales)}
            sub={`${day(best.date)} · ${best.orders_count} ${best.orders_count === 1 ? "order" : "orders"}`}
          />
        )}
        {reseller && (
          <Fact label="Top reseller" value={formatRm(reseller.sales)} sub={`${reseller.reseller_name} · ${reseller.orders_count} ${reseller.orders_count === 1 ? "order" : "orders"}`} />
        )}
        {biggest && (
          <Fact
            label="Biggest order"
            value={formatRm(biggest.amount)}
            sub={
              <>
                <Link href={`/admin/orders?order=${biggest.id}`} className="font-mono text-cyan-ink hover:underline">
                  {biggest.order_number}
                </Link>
                {[biggest.game_name, biggest.package_name].filter(Boolean).length > 0 && ` · ${[biggest.game_name, biggest.package_name].filter(Boolean).join(" ")}`}
              </>
            }
          />
        )}
      </dl>
    </section>
  );
}
