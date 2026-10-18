"use client";

/**
 * ADR-104 2026-10-15 — the Dashboard status strip and its System details
 * panel. Both read the one `/dashboard/health` response, so the strip's
 * "N need action" and the panel can never disagree. Purple is the page's
 * one attention colour (artifact README); each item still carries a word.
 */

import Link from "next/link";
import { useState, type ReactNode } from "react";
import { ArrowRight } from "@primeicons/react/arrow-right";
import { ChevronDown } from "@primeicons/react/chevron-down";
import { ChevronUp } from "@primeicons/react/chevron-up";
import { InfoTooltip } from "@/components/dashboard/InfoTooltip";
import { formatRm } from "@/components/reports/format";
import { formatMyrEquivalent, supplierBalanceLabel, type DashboardHealth } from "@/lib/dashboard";
import { describeAttention } from "@/lib/dashboard-briefing";

type Tone = "success" | "warning" | "danger" | "review" | "neutral";

const TONES: Record<Tone, string> = {
  success: "bg-success-surface text-success-ink",
  warning: "bg-warning-surface text-warning-ink",
  danger: "bg-danger-surface text-danger-ink",
  review: "bg-review-surface text-review-ink",
  neutral: "bg-neutral-surface text-neutral-ink",
};

function Pill({ tone, children }: { tone: Tone; children: ReactNode }) {
  return (
    <span className={`inline-flex h-[22px] items-center gap-1.5 whitespace-nowrap rounded-sm px-2 text-theme-xs font-semibold ${TONES[tone]}`}>
      <span aria-hidden className="size-1.5 rounded-full bg-current" />
      {children}
    </span>
  );
}

function clock(iso: string): string {
  return new Date(iso).toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit", timeZone: "Asia/Kuala_Lumpur" });
}

function when(iso: string): string {
  return `${new Date(iso).toLocaleDateString("en-MY", { day: "numeric", month: "short", timeZone: "Asia/Kuala_Lumpur" })}, ${clock(iso)}`;
}

function Row({ label, value, sub }: { label: ReactNode; value: ReactNode; sub?: ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-3 border-t border-border py-2.5 first:border-t-0 first:pt-0">
      <dt className="flex min-w-0 flex-wrap items-center gap-2 text-[13px] text-ink-muted">{label}</dt>
      <dd className="text-right text-theme-sm font-semibold whitespace-nowrap tabular-nums text-ink">
        {value}
        {sub && <small className="block text-theme-xs font-normal text-ink-subtle">{sub}</small>}
      </dd>
    </div>
  );
}

function Group({ title, definition, children }: { title: string; definition?: string; children: ReactNode }) {
  return (
    <div className="min-w-0 px-5 py-4 [&+&]:border-t [&+&]:border-border xl:[&+&]:border-t-0 xl:[&+&]:border-l">
      <h3 className="mb-3 flex items-center gap-1.5 text-[13px] font-semibold text-ink">
        {title}
        {definition && <InfoTooltip definition={definition} />}
      </h3>
      <dl>{children}</dl>
    </div>
  );
}

function sessionPill(session: { status: string } | null) {
  if (!session) return <Pill tone="neutral">No recent event</Pill>;
  return <Pill tone={session.status === "connected" ? "success" : "danger"}>{session.status === "connected" ? "Connected" : session.status}</Pill>;
}

export function StatusStrip({ health }: { health: DashboardHealth }) {
  const { items, needs_action_count: count } = health.attention;
  const hasRed = items.some((i) => i.level === "red");
  const [open, setOpen] = useState<boolean | null>(null);
  const isOpen = open ?? hasRed;
  const tasks = health.scheduled_tasks.tasks;
  const stale = tasks.filter((t) => t.stale);
  const criticalStale = stale.filter((t) => t.tier === "critical").length;

  return (
    <section aria-label="System status" className={`overflow-hidden rounded-lg border bg-surface shadow-xs ${count > 0 ? "border-purple-200" : "border-border"}`}>
      <div className={`flex flex-wrap items-center gap-x-4 gap-y-3 py-3 pr-4 pl-5 ${count > 0 ? "bg-purple-50" : ""}`}>
        <span className={`inline-flex items-center gap-2 whitespace-nowrap text-theme-sm font-semibold ${count > 0 ? "text-purple-ink" : "text-ink"}`}>
          <span aria-hidden className={`size-2 rounded-full ${count > 0 ? "bg-purple-600" : "bg-success-ink"}`} />
          {count > 0 ? `${count} ${count === 1 ? "needs" : "need"} action` : "All systems OK"}
        </span>
        <button
          type="button"
          aria-expanded={isOpen}
          onClick={() => setOpen(!isOpen)}
          className="order-2 ml-auto inline-flex h-[30px] items-center gap-1.5 rounded-md px-2.5 text-theme-xs font-medium text-ink-muted hover:bg-overlay hover:text-ink focus-visible:outline-2 focus-visible:outline-focus-ring md:order-3"
        >
          {isOpen ? "Hide" : "System details"}
          {isOpen ? <ChevronUp width={12} height={12} /> : <ChevronDown width={12} height={12} />}
        </button>
        {items.length > 0 && (
          <ul className="order-3 flex min-w-0 basis-full flex-wrap gap-2 md:order-2 md:flex-1 md:basis-auto">
            {items.map((item) => {
              const d = describeAttention(item);
              const body = (
                <>
                  {d.badge && d.tone && <Pill tone={d.tone}>{d.badge}</Pill>}
                  <span>{d.text}</span>
                  <ArrowRight width={11} height={11} className="text-ink-subtle" aria-hidden />
                </>
              );
              const cls = `inline-flex min-h-[30px] items-center gap-2 rounded-md py-1 text-[13px] focus-visible:outline-2 focus-visible:outline-focus-ring ${
                d.badge ? "border border-border bg-surface pr-2 pl-1 text-ink hover:border-border-control" : "bg-subtle px-2.5 text-ink-muted hover:text-ink"
              }`;
              return (
                <li key={`${item.key}:${item.subject ?? ""}`}>
                  {d.href ? (
                    <Link href={d.href} className={cls}>
                      {body}
                    </Link>
                  ) : (
                    <button type="button" className={cls} onClick={() => setOpen(true)}>
                      {body}
                    </button>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </div>

      {isOpen && (
        <div className="grid border-t border-border xl:grid-cols-3">
          <Group title="Suppliers" definition={health.suppliers_definition}>
            {health.suppliers.length === 0 && <Row label="No suppliers configured yet." value="" />}
            {health.suppliers.map((s) => (
              <Row
                key={s.id}
                label={
                  <>
                    {s.name}
                    {s.circuit_state === "open" && <Pill tone="danger">Circuit open</Pill>}
                    {s.low_balance && <Pill tone="warning">Low balance</Pill>}
                    {s.drift?.is_drifted && (
                      <span title={`Ledger ${s.drift.ledger_balance.toLocaleString()} vs polled ${s.drift.polled_balance.toLocaleString()} (threshold ${s.drift.threshold.toLocaleString()})`}>
                        <Pill tone="warning">Funding drift</Pill>
                      </span>
                    )}
                  </>
                }
                value={supplierBalanceLabel(s.balance, s.currency, s.is_pay_after)}
                sub={formatMyrEquivalent(s.balance_myr_equivalent) ?? undefined}
              />
            ))}
            <Row
              label={
                <>
                  Owed to reseller wallets <InfoTooltip definition={health.reseller_wallet_liability_definition} />
                </>
              }
              value={formatRm(health.reseller_wallet_liability_sen)}
              sub={`vs ${formatRm(Math.round(health.total_supplier_balance_myr_equivalent * 100))} at suppliers`}
            />
          </Group>

          <Group title="Messaging & jobs" definition={health.openwa_session_definition}>
            <Row label={<>Reseller Bot {sessionPill(health.openwa_session)}</>} value={health.openwa_session ? clock(health.openwa_session.at) : "—"} />
            <Row label={<>Customer WhatsApp {sessionPill(health.openwa_cs_session)}</>} value={health.openwa_cs_session ? clock(health.openwa_cs_session.at) : "—"} />
            <Row
              label={
                <>
                  Scheduled tasks
                  {criticalStale > 0 ? <Pill tone="danger">{criticalStale} critical late</Pill> : stale.length > 0 ? <Pill tone="warning">{stale.length} late</Pill> : <Pill tone="success">Running</Pill>}
                  <InfoTooltip definition={health.scheduled_tasks.definition} />
                </>
              }
              value={`${tasks.length - stale.length} of ${tasks.length} on time`}
            />
            {stale.length > 0 && (
              <ul className="mt-1 space-y-1 border-t border-border pt-2.5 text-theme-xs text-ink-muted">
                {stale.map((t) => (
                  <li key={t.name} title={t.last_error ?? undefined}>
                    <span className="text-ink">{t.name}</span> ({t.tier}) · last OK {t.last_ok_at ? when(t.last_ok_at) : "never"}
                    {t.last_error && <span className="text-danger-ink"> · {t.last_error.slice(0, 80)}</span>}
                  </li>
                ))}
              </ul>
            )}
          </Group>

          <Group title="Orders & queue">
            <Row label={<>Stuck or needs review <InfoTooltip definition={health.stuck_orders.definition} /></>} value={health.stuck_orders.value} />
            <Row label={<>Pending payments <InfoTooltip definition={health.pending_payments.definition} /></>} value={health.pending_payments.value} />
            <Row
              label={<>Queue <InfoTooltip definition={health.queue.definition} /></>}
              value={
                <>
                  {health.queue.pending} waiting · <span className={health.queue.failed > 0 ? "text-danger-ink" : ""}>{health.queue.failed} failed</span>
                </>
              }
            />
          </Group>
        </div>
      )}
    </section>
  );
}
