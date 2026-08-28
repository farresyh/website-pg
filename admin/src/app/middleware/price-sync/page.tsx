"use client";

/**
 * ADR-016: the full Price Sync Center — stat cards, Last Sync
 * Details, Pending Reactivation (SYNC-5/6, ADR-015), Manually
 * Dismissed Packages, and a paginated Sync History opening a
 * per-game Sync Details modal. Currency Rate (SYNC-3) was originally
 * omitted per ADR-016 decision #6 (no non-MYR supplier existed yet) —
 * superseded once ADR-030/033 actually built one: the "Rate used"
 * line in Last Sync Details and the new FX Rate History section below
 * (ADR-033 addendum) close SYNC-3 for real.
 */

import React, { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import {
  DataTable,
  DataTableTableContainer,
  DataTableTable,
  DataTableTHead,
  DataTableTHeadRow,
  DataTableTHeadCell,
  DataTableTBody,
  DataTableRow,
  DataTableCell,
} from "@/components/ui/datatable";
import { Tag } from "@/components/ui/tag";
import { Button } from "@/components/ui/button";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { getEcho } from "@/lib/echo";
import { ApiError } from "@/lib/api-client";
import {
  type PriceSyncRun,
  type PriceSyncRunPage,
  type PriceSyncStats,
  type PendingReactivation,
  type DismissedPackage,
  type DismissedPackagePage,
  type PendingPriceChange,
  type CurrencyRatePage,
  triggerPriceSync,
  getPriceSyncStats,
  listPriceSyncRuns,
  listPendingReactivations,
  approvePendingReactivation,
  dismissPendingReactivation,
  bulkApprovePendingReactivations,
  bulkDismissPendingReactivations,
  listDismissedPackages,
  restoreDismissedPackage,
  listPendingPriceChanges,
  approvePendingPriceChange,
  dismissPendingPriceChange,
  listCurrencyRates,
} from "@/lib/price-sync";
import SyncDetailsModal from "@/components/price-sync/SyncDetailsModal";
import PendingPriceChangeSection from "@/components/price-sync/PendingPriceChangeSection";
import FxRateHistorySection from "@/components/price-sync/FxRateHistorySection";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function daysInactive(deactivatedAt: string): number {
  return Math.max(0, Math.floor((Date.now() - new Date(deactivatedAt).getTime()) / 86_400_000));
}

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString() : "—";
}

const RUN_IN_FLIGHT = new Set<PriceSyncRun["status"]>(["queued", "running"]);

const runStatusSeverity: Record<PriceSyncRun["status"], "secondary" | "warn" | "success" | "danger"> = {
  queued: "secondary",
  running: "warn",
  success: "success",
  failed: "danger",
};

export default function PriceSyncPage() {
  const router = useRouter();
  const session = useClientSession();

  const [stats, setStats] = useState<PriceSyncStats | null>(null);
  const [run, setRun] = useState<PriceSyncRun | null>(null);
  const [pending, setPending] = useState<PendingReactivation[] | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [error, setError] = useState<string | null>(null);
  const [rowBusyId, setRowBusyId] = useState<number | null>(null);
  const [bulkBusy, setBulkBusy] = useState(false);

  const [dismissedPage, setDismissedPage] = useState<DismissedPackagePage | null>(null);
  const [dismissedPageNumber, setDismissedPageNumber] = useState(1);
  const [restoringId, setRestoringId] = useState<number | null>(null);

  const [pendingPriceChanges, setPendingPriceChanges] = useState<PendingPriceChange[] | null>(null);
  const [priceChangeBusyId, setPriceChangeBusyId] = useState<number | null>(null);

  const [historyPage, setHistoryPage] = useState<PriceSyncRunPage | null>(null);
  const [historyPageNumber, setHistoryPageNumber] = useState(1);
  const [detailsRunId, setDetailsRunId] = useState<number | null>(null);

  const [fxRatesPage, setFxRatesPage] = useState<CurrencyRatePage | null>(null);
  const [fxRatesPageNumber, setFxRatesPageNumber] = useState(1);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refreshStats = useCallback((token: string) => {
    getPriceSyncStats(token)
      .then(setStats)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load Price Sync stats.");
      });
  }, []);

  const refreshPending = useCallback((token: string) => {
    listPendingReactivations(token)
      .then((rows) => {
        setPending(rows);
        setSelected(new Set());
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load pending reactivations.");
      });
  }, []);

  const refreshDismissed = useCallback((token: string, page: number) => {
    listDismissedPackages(token, page)
      .then(setDismissedPage)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load dismissed packages.");
      });
  }, []);

  const refreshHistory = useCallback((token: string, page: number) => {
    listPriceSyncRuns(token, page)
      .then(setHistoryPage)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load sync history.");
      });
  }, []);

  const refreshPendingPriceChanges = useCallback((token: string) => {
    listPendingPriceChanges(token)
      .then(setPendingPriceChanges)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load pending price changes.");
      });
  }, []);

  const refreshFxRates = useCallback((token: string, page: number) => {
    listCurrencyRates(token, page)
      .then(setFxRatesPage)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load FX rate history.");
      });
  }, []);

  const refreshAll = useCallback(
    (token: string) => {
      refreshStats(token);
      refreshPending(token);
      refreshDismissed(token, dismissedPageNumber);
      refreshHistory(token, historyPageNumber);
      refreshPendingPriceChanges(token);
      refreshFxRates(token, fxRatesPageNumber);
    },
    [
      refreshStats,
      refreshPending,
      refreshDismissed,
      refreshHistory,
      refreshPendingPriceChanges,
      refreshFxRates,
      dismissedPageNumber,
      historyPageNumber,
      fxRatesPageNumber,
    ],
  );

  useEffect(() => {
    if (!session) return;
    refreshAll(session.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session]);

  useEffect(() => {
    if (!session) return;
    refreshDismissed(session.token, dismissedPageNumber);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, dismissedPageNumber]);

  useEffect(() => {
    if (!session) return;
    refreshHistory(session.token, historyPageNumber);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, historyPageNumber]);

  useEffect(() => {
    if (!session) return;
    refreshFxRates(session.token, fxRatesPageNumber);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, fxRatesPageNumber]);

  // ADR-047 decisions 1/3/4 — replaces the 2s poll above with a push
  // subscription on this run's own private channel. Admin-side screens
  // drop polling entirely once converted (unlike the storefront's
  // OrderStatusTracker) — if Reverb isn't reachable, this run's card
  // simply sits at its last-known state until the admin navigates away
  // and back, an accepted degrade for internal ops tooling.
  useEffect(() => {
    if (!session || !run || !RUN_IN_FLIGHT.has(run.status) || !process.env.NEXT_PUBLIC_REVERB_APP_KEY) return;

    const channelName = `price-sync-run.${run.id}`;
    const channel = getEcho().private(channelName);

    channel.listen(".price-sync-run.status.updated", (updated: PriceSyncRun) => {
      setRun(updated);
      if (!RUN_IN_FLIGHT.has(updated.status)) {
        refreshAll(session.token);
      }
    });

    return () => {
      getEcho().leave(channelName);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, run?.id, run?.status]);

  async function handleTrigger() {
    if (!session) return;
    setError(null);
    try {
      const created = await triggerPriceSync(session.token);
      setRun(created);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not start Price Sync.");
    }
  }

  function handleRefresh() {
    if (!session) return;
    setError(null);
    refreshAll(session.token);
  }

  async function handleApprove(packageId: number) {
    if (!session) return;
    setError(null);
    setRowBusyId(packageId);
    try {
      await approvePendingReactivation(session.token, packageId);
      setPending((prev) => prev?.filter((p) => p.id !== packageId) ?? null);
      refreshStats(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not approve reactivation.");
    } finally {
      setRowBusyId(null);
    }
  }

  async function handleDismiss(packageId: number) {
    if (!session) return;
    setError(null);
    setRowBusyId(packageId);
    try {
      await dismissPendingReactivation(session.token, packageId);
      setPending((prev) => prev?.filter((p) => p.id !== packageId) ?? null);
      refreshStats(session.token);
      refreshDismissed(session.token, dismissedPageNumber);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not dismiss reactivation.");
    } finally {
      setRowBusyId(null);
    }
  }

  async function handleBulk(action: "approve" | "dismiss") {
    if (!session || selected.size === 0 || bulkBusy) return;
    setError(null);
    setBulkBusy(true);
    const ids = Array.from(selected);
    try {
      if (action === "approve") {
        await bulkApprovePendingReactivations(session.token, ids);
      } else {
        await bulkDismissPendingReactivations(session.token, ids);
      }
      setPending((prev) => prev?.filter((p) => !selected.has(p.id)) ?? null);
      setSelected(new Set());
      refreshStats(session.token);
      if (action === "dismiss") refreshDismissed(session.token, dismissedPageNumber);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : `Could not bulk ${action}.`);
    } finally {
      setBulkBusy(false);
    }
  }

  async function handleRestore(pkg: DismissedPackage) {
    if (!session) return;
    setError(null);
    setRestoringId(pkg.id);
    try {
      await restoreDismissedPackage(session.token, pkg.id);
      setDismissedPage((prev) =>
        prev ? { ...prev, data: prev.data.filter((p) => p.id !== pkg.id) } : prev,
      );
      refreshStats(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not restore this package.");
    } finally {
      setRestoringId(null);
    }
  }

  async function handleApprovePriceChange(id: number) {
    if (!session) return;
    setError(null);
    setPriceChangeBusyId(id);
    try {
      await approvePendingPriceChange(session.token, id);
      setPendingPriceChanges((prev) => prev?.filter((p) => p.id !== id) ?? null);
      refreshStats(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not approve this price change.");
    } finally {
      setPriceChangeBusyId(null);
    }
  }

  async function handleDismissPriceChange(id: number) {
    if (!session) return;
    setError(null);
    setPriceChangeBusyId(id);
    try {
      await dismissPendingPriceChange(session.token, id);
      setPendingPriceChanges((prev) => prev?.filter((p) => p.id !== id) ?? null);
      refreshStats(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not dismiss this price change.");
    } finally {
      setPriceChangeBusyId(null);
    }
  }

  function toggleSelected(id: number) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }

  const isRunInFlight = run !== null && RUN_IN_FLIGHT.has(run.status);
  const lastHistoryRun = historyPage?.data[0] ?? null;

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Price Sync Center</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Propagates supplier price changes onto every promoted Package and turns off items the supplier can no
            longer fulfill (ADR-015). Only a swing past the review threshold is gated (ADR-025) — everything else
            applies with no approval either direction; a deactivated item only ever comes back via the review
            sections below.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outlined" onClick={handleRefresh}>
            Refresh
          </Button>
          <Button disabled={isRunInFlight} onClick={handleTrigger}>
            {isRunInFlight ? "Syncing…" : "Sync All Prices Now"}
          </Button>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {/* ADR-016 decision #1: stat cards */}
      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-6">
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Total Games</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{stats?.total_games ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Active Packages</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{stats?.active_packages ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Pending Reactivation</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{stats?.pending_reactivation_count ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Pending Price Change</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{stats?.pending_price_change_count ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Last Sync Status</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
            {stats?.last_sync_status ? (
              <Tag severity={runStatusSeverity[stats.last_sync_status]}>{stats.last_sync_status}</Tag>
            ) : (
              "—"
            )}
          </p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Last Sync</p>
          <p className="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{formatDateTime(stats?.last_sync_at ?? null)}</p>
        </div>
      </div>

      {/* Last Sync Details — a freshly-triggered run this session takes priority (live status), otherwise the newest Sync History row. */}
      {(run || lastHistoryRun) && (
        <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <h2 className="mb-2 text-sm font-semibold text-gray-800 dark:text-white/90">Last Sync Details</h2>
          {(() => {
            const shown = run ?? lastHistoryRun!;
            return (
              <>
                <p className="text-sm font-medium text-gray-800 dark:text-white/90">
                  Run #{shown.id} — <Tag severity={runStatusSeverity[shown.status]}>{shown.status}</Tag>
                  {shown.triggered_by && (
                    <span className="ml-2 text-theme-xs text-gray-400">triggered by {shown.triggered_by}</span>
                  )}
                </p>
                {shown.status === "failed" && shown.error_message && (
                  <p className="mt-1 text-sm text-error-600 dark:text-error-400">{shown.error_message}</p>
                )}
                {shown.status === "success" && shown.stats && (
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Catalog: {shown.stats.catalog_total} total ({shown.stats.catalog_created} new,{" "}
                    {shown.stats.catalog_updated} updated) · {shown.stats.price_changed} package
                    {shown.stats.price_changed === 1 ? "" : "s"} repriced · {shown.stats.deactivated} deactivated
                    {!!shown.stats.price_anomalies && (
                      <span className="text-warning-600 dark:text-warning-400"> · {shown.stats.price_anomalies} flagged (price anomaly)</span>
                    )}
                    {!!shown.stats.floor_rejected && (
                      <span className="text-error-600 dark:text-error-400"> · {shown.stats.floor_rejected} rejected (invalid price)</span>
                    )}
                  </p>
                )}
                {/* ADR-033 addendum decision 1/3: only ever non-empty once a real non-MYR supplier's sync runs. */}
                {shown.status === "success" && !!shown.stats?.fx_rates_used?.length && (
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Rate used: {shown.stats.fx_rates_used.map((r) => `1 ${r.from} = ${r.rate} ${r.to}`).join(", ")}
                  </p>
                )}
              </>
            );
          })()}
        </div>
      )}

      {/* Pending Reactivation (SYNC-5/6, ADR-015) */}
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">Pending Reactivation</h2>
        {selected.size > 0 && (
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={bulkBusy} onClick={() => handleBulk("approve")}>
              Approve All ({selected.size})
            </Button>
            <Button size="small" variant="outlined" disabled={bulkBusy} onClick={() => handleBulk("dismiss")}>
              Dismiss All ({selected.size})
            </Button>
          </div>
        )}
      </div>

      <div className="mb-8 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={pending ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="w-10 px-5 py-3">{null}</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Game</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cost</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Reseller Price</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Days Inactive</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const p = item as unknown as PendingReactivation;

                    return (
                      <DataTableRow key={p.id}>
                        <DataTableCell className="px-5 py-4">
                          <input type="checkbox" checked={selected.has(p.id)} onChange={() => toggleSelected(p.id)} />
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <span className="font-medium text-gray-800 dark:text-white/90">{p.name}</span>
                          <br />
                          <span className="text-theme-xs text-gray-400">{p.supplier_package_ref} · {p.supplier?.name}</span>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{p.game?.name ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.cost_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.reseller_cost_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{daysInactive(p.deactivated_at)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex gap-2">
                            <Button size="small" disabled={rowBusyId === p.id} onClick={() => handleApprove(p.id)}>
                              Approve
                            </Button>
                            <Button size="small" variant="outlined" disabled={rowBusyId === p.id} onClick={() => handleDismiss(p.id)}>
                              Dismiss
                            </Button>
                          </div>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {pending?.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
              Nothing pending — no deactivated package&apos;s supplier item has come back active.
            </p>
          )}
          {pending === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>

      {/* Pending Price Change (ADR-025 decision #8) */}
      <PendingPriceChangeSection
        items={pendingPriceChanges}
        busyId={priceChangeBusyId}
        onApprove={handleApprovePriceChange}
        onDismiss={handleDismissPriceChange}
      />

      {/* Manually Dismissed Packages (ADR-016 decision #3) */}
      <h2 className="mb-3 text-base font-semibold text-gray-800 dark:text-white/90">Manually Dismissed Packages</h2>
      <div className="mb-8 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={dismissedPage?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Game</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cost</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Reseller Price</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Days Inactive</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const p = item as unknown as DismissedPackage;

                    return (
                      <DataTableRow key={p.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <span className="font-medium text-gray-800 dark:text-white/90">{p.name}</span>
                          <br />
                          <span className="text-theme-xs text-gray-400">{p.supplier_package_ref} · {p.supplier?.name}</span>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{p.game?.name ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.cost_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.reseller_cost_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{daysInactive(p.deactivated_at)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Button size="small" variant="outlined" disabled={restoringId === p.id} onClick={() => handleRestore(p)}>
                            Restore
                          </Button>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {dismissedPage?.data.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
              No packages have been manually dismissed.
            </p>
          )}
          {dismissedPage === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>
      {dismissedPage && dismissedPage.last_page > 1 && (
        <div className="mb-8 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
          <span>Page {dismissedPage.current_page} of {dismissedPage.last_page} ({dismissedPage.total} total)</span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={dismissedPage.current_page <= 1} onClick={() => setDismissedPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={dismissedPage.current_page >= dismissedPage.last_page} onClick={() => setDismissedPageNumber((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}

      {/* Sync History (ADR-016 decision #1) */}
      <h2 className="mb-3 text-base font-semibold text-gray-800 dark:text-white/90">Sync History</h2>
      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={historyPage?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Run</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Triggered By</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Finished</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Price Changed</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Deactivated</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const h = item as unknown as PriceSyncRun;

                    return (
                      <DataTableRow key={h.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">#{h.id}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={runStatusSeverity[h.status]}>{h.status}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{h.triggered_by ?? "system"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatDateTime(h.finished_at)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{h.stats?.price_changed ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{h.stats?.deactivated ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Button size="small" variant="outlined" onClick={() => setDetailsRunId(h.id)}>
                            View Details
                          </Button>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {historyPage?.data.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No sync runs yet.</p>
          )}
          {historyPage === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>
      {historyPage && historyPage.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
          <span>Page {historyPage.current_page} of {historyPage.last_page} ({historyPage.total} total)</span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={historyPage.current_page <= 1} onClick={() => setHistoryPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={historyPage.current_page >= historyPage.last_page} onClick={() => setHistoryPageNumber((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}

      {/* FX Rate History (ADR-033 addendum decision 1/2/4) */}
      <div className="mt-8">
        <FxRateHistorySection
          page={fxRatesPage}
          onPrevious={() => setFxRatesPageNumber((p) => p - 1)}
          onNext={() => setFxRatesPageNumber((p) => p + 1)}
        />
      </div>

      {session && (
        <SyncDetailsModal
          isOpen={detailsRunId !== null}
          onClose={() => setDetailsRunId(null)}
          runId={detailsRunId}
          token={session.token}
        />
      )}
    </div>
  );
}
