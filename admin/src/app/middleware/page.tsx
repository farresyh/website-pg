"use client";

/**
 * MUI-1 — "Dashboard: supplier status cards, quick actions, recent API
 * calls, circuit-breaker state". Grilled 2026-08-28 (ADR-052): a thin,
 * manual-refresh landing page for the `/middleware` nav root — not a
 * new data source. Reuses `getDashboardHealth()` (the same endpoint
 * Admin Dashboard's System Health section already calls, ADR-045) for
 * supplier cards + circuit state, and `listRequestLogs()` (ADR-051)
 * for a recent-calls feed. Quick actions link out to `/middleware/suppliers`
 * rather than duplicate mutation logic here.
 */

import React, { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getDashboardHealth, type DashboardHealthSupplier } from "@/lib/dashboard";
import { listRequestLogs, type SupplierRequestLog } from "@/lib/request-logs";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

export default function MiddlewareDashboardPage() {
  const router = useRouter();
  const session = useClientSession();
  const [suppliers, setSuppliers] = useState<DashboardHealthSupplier[] | null>(null);
  const [recentCalls, setRecentCalls] = useState<SupplierRequestLog[] | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function load(token: string) {
    return Promise.all([getDashboardHealth(token), listRequestLogs(token, { per_page: 5 })]).then(([health, logs]) => {
      setSuppliers(health.suppliers);
      setRecentCalls(logs.data);
    });
  }

  useEffect(() => {
    if (!session) return;
    load(session.token).catch((err: unknown) => {
      setError(err instanceof ApiError ? err.message : "Could not load middleware status.");
    });

  }, [session]);

  async function handleRefresh() {
    if (!session) return;
    setError(null);
    setRefreshing(true);
    try {
      await load(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not refresh middleware status.");
    } finally {
      setRefreshing(false);
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Middleware Dashboard</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            MUI-1 — at-a-glance supplier health and recent activity. Circuit state and balance are cached snapshots
            (`CircuitBreaker::state()`), never a live ping.
          </p>
        </div>
        <Button variant="outlined" disabled={refreshing || !session} onClick={handleRefresh}>
          {refreshing ? "Refreshing…" : "Refresh"}
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">Suppliers</h2>
          <Button size="small" variant="outlined" onClick={() => router.push("/middleware/suppliers")}>
            Manage Suppliers →
          </Button>
        </div>

        {suppliers === null && !error && <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        {suppliers?.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">No suppliers configured yet.</p>
        )}

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {suppliers?.map((s) => (
            <div key={s.id} className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
              <div className="flex items-center justify-between">
                <span className="font-semibold text-gray-800 dark:text-white/90">{s.name}</span>
                <Tag severity={s.circuit_state === "closed" ? "success" : "danger"}>
                  {s.circuit_state === "closed" ? "Healthy" : "Circuit Open"}
                </Tag>
              </div>
              <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Balance: {formatRm(s.balance)}</p>
            </div>
          ))}
        </div>
      </div>

      <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">Recent API Calls</h2>
          <Button size="small" variant="outlined" onClick={() => router.push("/middleware/request-logs")}>
            View all →
          </Button>
        </div>

        {recentCalls === null && !error && <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        {recentCalls?.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">No supplier calls logged yet.</p>
        )}

        <div className="space-y-2">
          {recentCalls?.map((log) => (
            <div
              key={log.id}
              className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700"
            >
              <span className="font-medium text-gray-800 dark:text-white/90">
                {log.supplier?.name ?? "—"} · {log.call_type}
              </span>
              <Tag severity={log.outcome === "success" ? "success" : "danger"}>
                {log.outcome}
              </Tag>
              <span className="text-gray-500 dark:text-gray-400">{new Date(log.created_at).toLocaleString()}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
