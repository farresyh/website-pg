"use client";

/**
 * DASH-1..6 (docs/prd.md §6.2, ADR-045). Auth-guard logic below is
 * unchanged from the original placeholder — reads the Bearer token
 * stashed by login/page.tsx and calls GET /api/me (ADR-009), including
 * the `cancelled` guard against the abort race ADR-023 already fixed
 * live (a page navigation aborting this fetch was previously misread
 * as "session invalid" and wiped a valid session).
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch, ApiError } from "@/lib/api-client";
import { getClientSession, clearClientSession } from "@/lib/session";
import { useTheme } from "@/context/ThemeContext";
import { Tag } from "@/components/ui/tag";
import { ChartLineIcon, ListIcon, TrendUpIcon, TagIcon } from "@/icons";
import { DashboardKpiCard } from "@/components/dashboard/DashboardKpiCard";
import { InfoTooltip } from "@/components/dashboard/InfoTooltip";
import { ConversionFunnelChart } from "@/components/dashboard/ConversionFunnelChart";
import { HourlyActivityChart } from "@/components/dashboard/HourlyActivityChart";
import { HorizontalBarList } from "@/components/reports/HorizontalBarList";
import { formatRm, toRm } from "@/components/reports/format";
import {
  getDashboardSummary,
  getDashboardHealth,
  getDashboardFunnel,
  getDashboardTopGames,
  getDashboardHourlyActivity,
  formatSupplierBalance,
  formatMyrEquivalent,
  type DashboardSummary,
  type DashboardHealth,
  type DashboardFunnel,
  type DashboardTopGames,
  type DashboardHourlyActivity,
} from "@/lib/dashboard";

interface Admin {
  id: number;
  name: string;
  email: string;
  role: "super_admin" | "admin";
}

const HEALTH_POLL_MS = 60_000;

function kl_today(): string {
  // Asia/Kuala_Lumpur "today" as YYYY-MM-DD — matches the backend's own
  // day boundary for the day-selector's default.
  return new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Kuala_Lumpur" });
}

function lastSevenDays(): string[] {
  const days: string[] = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date();
    d.setDate(d.getDate() - i);
    days.push(d.toLocaleDateString("en-CA", { timeZone: "Asia/Kuala_Lumpur" }));
  }
  return days;
}

export default function AdminDashboardPage() {
  const router = useRouter();
  const { theme } = useTheme();
  const [admin, setAdmin] = useState<Admin | null>(null);
  const [authError, setAuthError] = useState<string | null>(null);
  const [token, setToken] = useState<string | null>(null);

  const [summary, setSummary] = useState<DashboardSummary | null>(null);
  const [health, setHealth] = useState<DashboardHealth | null>(null);
  const [funnel, setFunnel] = useState<DashboardFunnel | null>(null);
  const [topGames, setTopGames] = useState<DashboardTopGames | null>(null);
  const [hourly, setHourly] = useState<DashboardHourlyActivity | null>(null);
  const [selectedDate, setSelectedDate] = useState<string>(kl_today());
  const [sectionError, setSectionError] = useState<string | null>(null);

  useEffect(() => {
    const session = getClientSession();

    if (!session) {
      router.replace("/login");
      return;
    }

    let cancelled = false;

    apiFetch<Admin>("/api/me", { token: session.token })
      .then((data) => {
        if (!cancelled) {
          setAdmin(data);
          setToken(session.token);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        clearClientSession();
        setAuthError(err instanceof ApiError ? err.message : "Session expired.");
        router.replace("/login");
      });

    return () => {
      cancelled = true;
    };
  }, [router]);

  // DASH-1
  useEffect(() => {
    if (!token) return;
    getDashboardSummary(token)
      .then(setSummary)
      .catch((err: unknown) => setSectionError(err instanceof ApiError ? err.message : "Could not load dashboard summary."));
  }, [token]);

  // DASH-2 — the only section polled on a timer (60s, always-on, ADR-045 decision 22/23).
  useEffect(() => {
    if (!token) return;

    let cancelled = false;
    const load = () => {
      getDashboardHealth(token)
        .then((data) => {
          if (!cancelled) setHealth(data);
        })
        .catch(() => undefined);
    };

    load();
    const interval = setInterval(load, HEALTH_POLL_MS);

    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, [token]);

  // DASH-3
  useEffect(() => {
    if (!token) return;
    getDashboardFunnel(token).then(setFunnel).catch(() => undefined);
  }, [token]);

  // DASH-4
  useEffect(() => {
    if (!token) return;
    getDashboardTopGames(token, 5).then(setTopGames).catch(() => undefined);
  }, [token]);

  // DASH-5
  useEffect(() => {
    if (!token) return;
    getDashboardHourlyActivity(token, selectedDate).then(setHourly).catch(() => undefined);
  }, [token, selectedDate]);

  if (authError) {
    return <p className="text-sm text-red-600 dark:text-red-400">{authError}</p>;
  }

  if (!admin || !token) {
    return <p className="text-sm text-black/60 dark:text-white/60">Loading…</p>;
  }

  return (
    <div>
      <h1 className="text-xl font-semibold">Dashboard</h1>
      <p className="mt-1 mb-6 text-sm text-black/60 dark:text-white/60">
        Signed in as {admin.name} ({admin.role}).
      </p>

      {sectionError && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {sectionError}
        </p>
      )}

      {/* DASH-1 — KPI cards */}
      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <DashboardKpiCard
          icon={<ChartLineIcon width={18} height={18} />}
          color="blue"
          label="Sales Today"
          value={summary ? formatRm(summary.sales_today.value) : "—"}
          metric={summary?.sales_today ?? null}
        />
        <DashboardKpiCard
          icon={<ListIcon width={18} height={18} />}
          color="indigo"
          label="Orders Today"
          value={summary ? summary.orders_today.value.toLocaleString() : "—"}
          metric={summary?.orders_today ?? null}
        />
        <DashboardKpiCard
          icon={<TrendUpIcon width={18} height={18} />}
          color="green"
          label="Profit Today"
          value={summary ? formatRm(summary.profit_today.value) : "—"}
          metric={summary?.profit_today ?? null}
        />
        <DashboardKpiCard
          icon={<TagIcon width={18} height={18} />}
          color="amber"
          label="Vouchers Issued Today"
          value={summary ? summary.vouchers_issued_today.value.toLocaleString() : "—"}
          sub={summary ? formatRm(summary.vouchers_issued_today.amount_sen) : undefined}
          metric={summary?.vouchers_issued_today ?? null}
        />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* DASH-2 — System Health, auto-refreshes every 60s */}
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">System Health</h2>
          {health ? (
            <div>
              <ul className="mb-4 space-y-2">
                {health.suppliers.map((s) => (
                  <li key={s.id} className="flex items-center justify-between text-theme-sm">
                    <span className="flex items-center gap-2 font-medium text-gray-700 dark:text-gray-200">
                      {s.name}
                      <Tag severity={s.circuit_state === "closed" ? "success" : "danger"}>{s.circuit_state}</Tag>
                      {s.low_balance && <Tag severity="warn">low balance</Tag>}
                      {/* ADR-083 decision 6 — drift is null when this supplier has no drift_threshold configured, not when it's in sync; only ever shown once it's actually drifted. */}
                      {s.drift?.is_drifted && (
                        <Tag
                          severity="warn"
                          title={`Ledger ${s.drift.ledger_balance.toLocaleString()} vs polled ${s.drift.polled_balance.toLocaleString()} — variance ${s.drift.variance.toLocaleString()} (threshold ${s.drift.threshold.toLocaleString()})`}
                        >
                          funding drift
                        </Tag>
                      )}
                    </span>
                    <span className="text-right">
                      <span
                        className={
                          s.low_balance
                            ? "tabular-nums font-medium text-warning-600 dark:text-warning-400"
                            : "tabular-nums text-gray-500 dark:text-gray-400"
                        }
                      >
                        {formatSupplierBalance(s.balance, s.currency)}
                      </span>
                      {formatMyrEquivalent(s.balance_myr_equivalent) && (
                        <span className="ml-1 text-theme-xs tabular-nums text-gray-400">{formatMyrEquivalent(s.balance_myr_equivalent)}</span>
                      )}
                    </span>
                  </li>
                ))}
                {health.suppliers.length === 0 && (
                  <li className="text-theme-sm text-gray-400 dark:text-gray-500">No suppliers configured yet.</li>
                )}
                {/* PR-F build addendum decision 5 — Reseller Bot channel's OpenWA session, an active chip (never a silent gap) since a down session is a reseller's live paid ordering path. */}
                <li className="flex items-center justify-between text-theme-sm">
                  <span className="flex items-center gap-2 font-medium text-gray-700 dark:text-gray-200">
                    Reseller Bot (WhatsApp)
                    {health.openwa_session ? (
                      <Tag severity={health.openwa_session.status === "connected" ? "success" : "danger"}>
                        {health.openwa_session.status}
                      </Tag>
                    ) : (
                      <Tag severity="secondary">not provisioned</Tag>
                    )}
                  </span>
                  {health.openwa_session && (
                    <span className="text-theme-xs text-gray-400 dark:text-gray-500">
                      {new Date(health.openwa_session.at).toLocaleTimeString("en-MY", { hour: "2-digit", minute: "2-digit" })}
                    </span>
                  )}
                </li>
              </ul>
              <div className="flex items-center gap-1.5 pb-1">
                <span className="text-theme-xs text-gray-400 dark:text-gray-500">Supplier status/balance</span>
                <InfoTooltip definition={health.suppliers_definition} />
              </div>
              <div className="grid grid-cols-3 gap-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                <div>
                  <p className="flex items-center gap-1 text-theme-xs text-gray-400 dark:text-gray-500">
                    Stuck Orders <InfoTooltip definition={health.stuck_orders.definition} />
                  </p>
                  <p className="text-base font-semibold text-gray-800 dark:text-white/90">{health.stuck_orders.value}</p>
                </div>
                <div>
                  <p className="flex items-center gap-1 text-theme-xs text-gray-400 dark:text-gray-500">
                    Pending Payments <InfoTooltip definition={health.pending_payments.definition} />
                  </p>
                  <p className="text-base font-semibold text-gray-800 dark:text-white/90">{health.pending_payments.value}</p>
                </div>
                <div>
                  <p className="flex items-center gap-1 text-theme-xs text-gray-400 dark:text-gray-500">
                    Queue <InfoTooltip definition={health.queue.definition} />
                  </p>
                  <p className="text-base font-semibold text-gray-800 dark:text-white/90">
                    {health.queue.pending}
                    {health.queue.failed > 0 && <span className="ml-1 text-error-600 dark:text-error-400">({health.queue.failed} failed)</span>}
                  </p>
                </div>
              </div>
            </div>
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>

        {/* DASH-3 — Conversion Funnel */}
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Conversion Funnel (last 7 days)</h2>
          {funnel ? <ConversionFunnelChart funnel={funnel} theme={theme} /> : <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        </div>

        {/* DASH-4 — Top Games Weekly */}
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="mb-4 flex items-center gap-1.5">
            <h2 className="text-sm font-semibold text-gray-800 dark:text-white/90">Top Games This Week</h2>
            {topGames && <InfoTooltip definition={topGames.definition} />}
          </div>
          {topGames ? (
            <HorizontalBarList
              items={topGames.games.map((g) => ({
                label: g.game_name,
                value: toRm(g.sales),
                sublabel: `${g.orders_count} orders · ${
                  g.comparison.direction === "new" ? "New" : g.comparison.direction === "flat" ? "—" : `${g.comparison.direction === "up" ? "▲" : "▼"} ${g.comparison.pct}%`
                }`,
              }))}
              formatValue={(v) => `RM ${v.toLocaleString("en-MY", { maximumFractionDigits: 0 })}`}
            />
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>

        {/* DASH-5 — Hourly Activity */}
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="mb-4 flex items-center justify-between gap-3">
            <div className="flex items-center gap-1.5">
              <h2 className="text-sm font-semibold text-gray-800 dark:text-white/90">Hourly Activity</h2>
              {hourly && <InfoTooltip definition={hourly.definition} />}
            </div>
            <div className="flex flex-wrap justify-end gap-1">
              {lastSevenDays().map((date) => (
                <button
                  key={date}
                  type="button"
                  onClick={() => setSelectedDate(date)}
                  className={`rounded-md px-2 py-1 text-theme-xs font-medium ${
                    selectedDate === date
                      ? "bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400"
                      : "text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/[0.05]"
                  }`}
                >
                  {new Date(`${date}T00:00:00`).toLocaleDateString("en-MY", { day: "numeric", month: "short" })}
                </button>
              ))}
            </div>
          </div>
          {hourly ? <HourlyActivityChart hours={hourly.hours} theme={theme} /> : <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        </div>
      </div>
    </div>
  );
}
