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
import { useReconcileOnResume } from "@/lib/useReconcileOnResume";
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
  getPriceSyncRun,
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
  approveAllPendingPriceChanges,
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
  partial: "warn",
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

  const refreshStats = useCallback(() => {
    getPriceSyncStats()
      .then(setStats)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load Price Sync stats.");
      });
  }, []);

  const refreshPending = useCallback(() => {
    listPendingReactivations()
      .then((rows) => {
        setPending(rows);
        setSelected(new Set());
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load pending reactivations.");
      });
  }, []);

  const refreshDismissed = useCallback((page: number) => {
    listDismissedPackages(page)
      .then(setDismissedPage)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load dismissed packages.");
      });
  }, []);

  const refreshHistory = useCallback((page: number) => {
    listPriceSyncRuns(page)
      .then(setHistoryPage)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load sync history.");
      });
  }, []);

  const refreshPendingPriceChanges = useCallback(() => {
    listPendingPriceChanges()
      .then(setPendingPriceChanges)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load pending price changes.");
      });
  }, []);

  const refreshFxRates = useCallback((page: number) => {
    listCurrencyRates(page)
      .then(setFxRatesPage)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load FX rate history.");
      });
  }, []);

  const refreshAll = useCallback(
    () => {
      refreshStats();
      refreshPending();
      refreshDismissed(dismissedPageNumber);
      refreshHistory(historyPageNumber);
      refreshPendingPriceChanges();
      refreshFxRates(fxRatesPageNumber);
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
    refreshAll();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session]);

  useEffect(() => {
    if (!session) return;
    refreshDismissed(dismissedPageNumber);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, dismissedPageNumber]);

  useEffect(() => {
    if (!session) return;
    refreshHistory(historyPageNumber);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, historyPageNumber]);

  useEffect(() => {
    if (!session) return;
    refreshFxRates(fxRatesPageNumber);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, fxRatesPageNumber]);

  // ADR-047 decisions 1/3/4 — replaces the 2s poll above with a push
  // subscription on this run's own private channel. Admin-side screens
  // drop polling entirely once converted (unlike the storefront's
  // OrderStatusTracker) — if Reverb isn't reachable, this run's card
  // simply sits at its last-known state until the admin navigates away
  // and back, an accepted degrade for internal ops tooling (closed by
  // the reconcile-on-resume hook below).
  //
  // ADR-047 2026-09-28 addendum: deps deliberately exclude `run?.status`
  // now — it used to be a dep, which meant this effect fully
  // leave()+resubscribe()'d the channel (a fresh private-channel auth
  // round-trip) on every status tick, including the one the listener
  // itself just received. A run that fails fast (SyncSupplierPricesJob
  // throwing immediately, e.g. a supplier outage) can flip
  // running→failed inside that resubscribe's own auth window — with
  // this screen's own polling already dropped, that final event had
  // nowhere else to land, leaving the card stuck on "running" forever.
  // Subscribing once per `run.id` and staying subscribed regardless of
  // later status ticks (same "a subscription costs nothing while idle"
  // shape `admin-orders`/`backups` already use) closes the race instead
  // of just narrowing it.
  useEffect(() => {
    if (!session || !run || !RUN_IN_FLIGHT.has(run.status) || !process.env.NEXT_PUBLIC_REVERB_APP_KEY) return;

    const channelName = `price-sync-run.${run.id}`;
    const channel = getEcho().private(channelName);

    channel.listen(".price-sync-run.status.updated", (updated: PriceSyncRun) => {
      setRun(updated);
      if (!RUN_IN_FLIGHT.has(updated.status)) {
        refreshAll();
      }
    });

    return () => {
      getEcho().leave(channelName);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, run?.id]);

  useReconcileOnResume(
    useCallback(() => {
      if (!session || !run) return;
      refreshHistory(historyPageNumber);
      if (RUN_IN_FLIGHT.has(run.status)) {
        getPriceSyncRun(run.id).then(setRun).catch(() => {});
      } else {
        refreshAll();
      }
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [session, run?.id, run?.status]),
  );

  async function handleTrigger() {
    if (!session) return;
    setError(null);
    try {
      const created = await triggerPriceSync();
      setRun(created);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not start Price Sync.");
    }
  }

  function handleRefresh() {
    if (!session) return;
    setError(null);
    refreshAll();
  }

  async function handleApprove(packageId: number) {
    if (!session) return;
    setError(null);
    setRowBusyId(packageId);
    try {
      await approvePendingReactivation(packageId);
      setPending((prev) => prev?.filter((p) => p.id !== packageId) ?? null);
      refreshStats();
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
      await dismissPendingReactivation(packageId);
      setPending((prev) => prev?.filter((p) => p.id !== packageId) ?? null);
      refreshStats();
      refreshDismissed(dismissedPageNumber);
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
        await bulkApprovePendingReactivations(ids);
      } else {
        await bulkDismissPendingReactivations(ids);
      }
      setPending((prev) => prev?.filter((p) => !selected.has(p.id)) ?? null);
      setSelected(new Set());
      refreshStats();
      if (action === "dismiss") refreshDismissed(dismissedPageNumber);
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
      await restoreDismissedPackage(pkg.id);
      setDismissedPage((prev) =>
        prev ? { ...prev, data: prev.data.filter((p) => p.id !== pkg.id) } : prev,
      );
      refreshStats();
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
      await approvePendingPriceChange(id);
      setPendingPriceChanges((prev) => prev?.filter((p) => p.id !== id) ?? null);
      refreshStats();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not approve this price change.");
    } finally {
      setPriceChangeBusyId(null);
    }
  }

  async function handleApproveAllPriceChanges(supplierId: number) {
    if (!session) return;
    setError(null);
    try {
      await approveAllPendingPriceChanges(supplierId);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not approve these price changes.");
    } finally {
      // Rows apply one by one, so a failure can leave some done.
      refreshPendingPriceChanges();
      refreshStats();
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
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1 basis-80">
          <h1 className="text-page-title font-semibold text-ink">Price Sync Center</h1>
          <p className="mt-1 text-sm text-ink-muted">
            Propagates supplier price changes onto every promoted Package and turns off items the supplier can no
            longer fulfill (ADR-015). A rise applies at once; a big drop is held and keeps selling at the old cost
            (ADR-138). A deactivated item only ever comes back via the review sections below.
          </p>
        </div>
        <div className="flex shrink-0 gap-2">
          <Button variant="outlined" onClick={handleRefresh}>
            Refresh
          </Button>
          <Button disabled={isRunInFlight} onClick={handleTrigger}>
            {isRunInFlight ? "Syncing…" : "Sync All Prices Now"}
          </Button>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-danger-surface px-4 py-3 text-sm text-danger-ink">
          {error}
        </p>
      )}

      {/* ADR-016 decision #1: stat cards */}
      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-6">
        <div className="rounded-2xl border border-border bg-surface p-4">
          <p className="text-theme-xs text-ink-muted">Total Games</p>
          <p className="mt-1 text-lg font-semibold text-ink">{stats?.total_games ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-border bg-surface p-4">
          <p className="text-theme-xs text-ink-muted">Active Packages</p>
          <p className="mt-1 text-lg font-semibold text-ink">{stats?.active_packages ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-border bg-surface p-4">
          <p className="text-theme-xs text-ink-muted">Pending Reactivation</p>
          <p className="mt-1 text-lg font-semibold text-ink">{stats?.pending_reactivation_count ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-border bg-surface p-4">
          <p className="text-theme-xs text-ink-muted">Held Price Changes</p>
          <p className="mt-1 text-lg font-semibold text-ink">{stats?.pending_price_change_count ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-border bg-surface p-4">
          <p className="text-theme-xs text-ink-muted">Last Sync Status</p>
          <p className="mt-1 text-lg font-semibold text-ink">
            {stats?.last_sync_status ? (
              <Tag severity={runStatusSeverity[stats.last_sync_status]}>{stats.last_sync_status}</Tag>
            ) : (
              "—"
            )}
          </p>
        </div>
        <div className="rounded-2xl border border-border bg-surface p-4">
          <p className="text-theme-xs text-ink-muted">Last Sync</p>
          <p className="mt-1 text-sm font-medium text-ink">{formatDateTime(stats?.last_sync_at ?? null)}</p>
        </div>
      </div>

      {/* Last Sync Details — a freshly-triggered run this session takes priority (live status), otherwise the newest Sync History row. */}
      {(run || lastHistoryRun) && (
        <div className="mb-6 rounded-2xl border border-border bg-surface p-5">
          <h2 className="mb-2 text-sm font-semibold text-ink">Last Sync Details</h2>
          {(() => {
            const shown = run ?? lastHistoryRun!;
            return (
              <>
                <p className="text-sm font-medium text-ink">
                  Run #{shown.id} — <Tag severity={runStatusSeverity[shown.status]}>{shown.status}</Tag>
                  {shown.triggered_by && (
                    <span className="ml-2 text-theme-xs text-ink-subtle">triggered by {shown.triggered_by}</span>
                  )}
                </p>
                {shown.stats?.failed_suppliers?.length ? (
                  <ul className="mt-1 text-sm text-danger-ink">
                    {shown.stats.failed_suppliers.map((f) => (
                      <li key={f.slug}>
                        {f.slug} failed: {f.error}
                      </li>
                    ))}
                  </ul>
                ) : (
                  shown.status === "failed" &&
                  shown.error_message && <p className="mt-1 text-sm text-danger-ink">{shown.error_message}</p>
                )}
                {(shown.status === "success" || shown.status === "partial") && shown.stats && (
                  <p className="mt-1 text-sm text-ink-muted">
                    Catalog: {shown.stats.catalog_total} total ({shown.stats.catalog_created} new,{" "}
                    {shown.stats.catalog_updated} updated) · {shown.stats.price_changed} package
                    {shown.stats.price_changed === 1 ? "" : "s"} repriced · {shown.stats.deactivated} deactivated
                    {!!shown.stats.price_anomalies && (
                      <span className="text-warning-ink"> · {shown.stats.price_anomalies} flagged (price anomaly)</span>
                    )}
                    {!!shown.stats.floor_rejected && (
                      <span className="text-danger-ink"> · {shown.stats.floor_rejected} rejected (invalid price)</span>
                    )}
                  </p>
                )}
                {/* ADR-033 addendum decision 1/3: only ever non-empty once a real non-MYR supplier's sync runs. ADR-134 decision 15: the deposit's rate, with the market and the spread beside it. */}
                {(shown.status === "success" || shown.status === "partial") && !!shown.stats?.fx_rates_used?.length && (
                  <p className="mt-1 text-sm text-ink-muted">
                    Rate used: {shown.stats.fx_rates_used.map(describeRateUsed).join("; ")}
                  </p>
                )}
              </>
            );
          })()}
        </div>
      )}

      {/* Pending Reactivation (SYNC-5/6, ADR-015) */}
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-section-title font-semibold text-ink">Pending Reactivation</h2>
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

      <div className="mb-8 overflow-hidden rounded-2xl border border-border bg-surface">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={pending ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-border">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="w-10 px-5 py-3">{null}</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Package</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Game</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Cost</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Selling Price</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Days Inactive</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-border">
                  {({ item }) => {
                    const p = item as unknown as PendingReactivation;

                    return (
                      <DataTableRow key={p.id}>
                        <DataTableCell className="px-5 py-4">
                          <input type="checkbox" checked={selected.has(p.id)} onChange={() => toggleSelected(p.id)} />
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <span className="font-medium text-ink">{p.name}</span>
                          <br />
                          <span className="text-theme-xs text-ink-subtle">{p.supplier_package_ref} · {p.supplier?.name}</span>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{p.game?.name ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.cost_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.standard_selling_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{daysInactive(p.deactivated_at)}</DataTableCell>
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
            <p className="p-6 text-center text-sm text-ink-muted">
              Nothing pending — no deactivated package&apos;s supplier item has come back active.
            </p>
          )}
          {pending === null && !error && (
            <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>
          )}
        </div>
      </div>

      {/* Held Price Changes (ADR-138) */}
      <PendingPriceChangeSection
        items={pendingPriceChanges}
        busyId={priceChangeBusyId}
        onApprove={handleApprovePriceChange}
        onApproveAll={handleApproveAllPriceChanges}
      />

      {/* Manually Dismissed Packages (ADR-016 decision #3) */}
      <h2 className="mb-3 text-section-title font-semibold text-ink">Manually Dismissed Packages</h2>
      <div className="mb-8 overflow-hidden rounded-2xl border border-border bg-surface">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={dismissedPage?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-border">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Package</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Game</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Cost</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Selling Price</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Days Inactive</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-border">
                  {({ item }) => {
                    const p = item as unknown as DismissedPackage;

                    return (
                      <DataTableRow key={p.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <span className="font-medium text-ink">{p.name}</span>
                          <br />
                          <span className="text-theme-xs text-ink-subtle">{p.supplier_package_ref ? `${p.supplier_package_ref} · ${p.supplier?.name}` : "Combo"}</span>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{p.game?.name ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.cost_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.standard_selling_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{daysInactive(p.deactivated_at)}</DataTableCell>
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
            <p className="p-6 text-center text-sm text-ink-muted">
              No packages have been manually dismissed.
            </p>
          )}
          {dismissedPage === null && !error && (
            <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>
          )}
        </div>
      </div>
      {dismissedPage && dismissedPage.last_page > 1 && (
        <div className="mb-8 flex items-center justify-between text-sm text-ink-muted">
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
      <h2 className="mb-3 text-section-title font-semibold text-ink">Sync History</h2>
      <div className="overflow-hidden rounded-2xl border border-border bg-surface">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={historyPage?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-border">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Run</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Triggered By</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Finished</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Price Changed</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Deactivated</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-border">
                  {({ item }) => {
                    const h = item as unknown as PriceSyncRun;

                    return (
                      <DataTableRow key={h.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-ink">#{h.id}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={runStatusSeverity[h.status]}>{h.status}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{h.triggered_by ?? "system"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{formatDateTime(h.finished_at)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{h.stats?.price_changed ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{h.stats?.deactivated ?? "—"}</DataTableCell>
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
            <p className="p-6 text-center text-sm text-ink-muted">No sync runs yet.</p>
          )}
          {historyPage === null && !error && (
            <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>
          )}
        </div>
      </div>
      {historyPage && historyPage.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-ink-muted">
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
        />
      )}
    </div>
  );
}

type RateUsed = NonNullable<NonNullable<PriceSyncRun["stats"]>["fx_rates_used"]>[number];

/** "1 IDR = 0.00023913 MYR, what the deposit cost (market 0.000224, +6.75%)"; a run before ADR-134 priced at the market rate. */
function describeRateUsed(r: RateUsed): string {
  const rate = `1 ${r.from} = ${Number(r.rate.toPrecision(5))} ${r.to}`;
  if (r.source !== "deposit pool") return `${rate} (market rate)`;
  if (r.market_rate == null || r.spread_pct == null) return `${rate}, what the deposit cost (market rate unavailable)`;
  return `${rate}, what the deposit cost (market ${r.market_rate}, ${r.spread_pct >= 0 ? "+" : ""}${r.spread_pct.toFixed(2)}%)`;
}
