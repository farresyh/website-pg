"use client";

/**
 * ADR-015: manual trigger + status polling for Price Propagation +
 * Deactivation Detection, plus the Pending Reactivation queue
 * (SYNC-5/6). This is deliberately the minimum surface ADR-015 itself
 * calls for (decision #7) — the full Price Sync Center dashboard
 * (stat cards, Sync History, Manually Dismissed Packages) is ADR-016,
 * a later, separate pass.
 */

import React, { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Button from "@/components/ui/button/Button";
import { getClientSession } from "@/lib/session";
import type { SessionPayload } from "@/lib/auth";
import { ApiError } from "@/lib/api-client";
import {
  type PriceSyncRun,
  type PendingReactivation,
  triggerPriceSync,
  getPriceSyncRun,
  listPendingReactivations,
  approvePendingReactivation,
  dismissPendingReactivation,
  bulkApprovePendingReactivations,
  bulkDismissPendingReactivations,
} from "@/lib/price-sync";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function daysInactive(deactivatedAt: string): number {
  return Math.max(0, Math.floor((Date.now() - new Date(deactivatedAt).getTime()) / 86_400_000));
}

const RUN_IN_FLIGHT = new Set<PriceSyncRun["status"]>(["queued", "running"]);

export default function PriceSyncPage() {
  const router = useRouter();
  const [session, setSession] = useState<SessionPayload | null>(null);

  const [run, setRun] = useState<PriceSyncRun | null>(null);
  const [pending, setPending] = useState<PendingReactivation[] | null>(null);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [error, setError] = useState<string | null>(null);
  const [rowBusyId, setRowBusyId] = useState<number | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    setSession(s);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refreshPending = useCallback(
    (token: string) => {
      listPendingReactivations(token)
        .then((rows) => {
          setPending(rows);
          setSelected(new Set());
        })
        .catch((err: unknown) => {
          setError(err instanceof ApiError ? err.message : "Could not load pending reactivations.");
        });
    },
    [],
  );

  useEffect(() => {
    if (!session) return;
    refreshPending(session.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session]);

  useEffect(() => {
    if (!session || !run || !RUN_IN_FLIGHT.has(run.status)) {
      if (pollRef.current) clearInterval(pollRef.current);
      return;
    }

    pollRef.current = setInterval(() => {
      getPriceSyncRun(session.token, run.id)
        .then((updated) => {
          setRun(updated);
          if (!RUN_IN_FLIGHT.has(updated.status)) {
            refreshPending(session.token);
          }
        })
        .catch(() => {
          // A transient poll failure isn't fatal — the next tick retries.
        });
    }, 2000);

    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
    };
  }, [session, run, refreshPending]);

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

  async function handleApprove(packageId: number) {
    if (!session) return;
    setError(null);
    setRowBusyId(packageId);
    try {
      await approvePendingReactivation(session.token, packageId);
      setPending((prev) => prev?.filter((p) => p.id !== packageId) ?? null);
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
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not dismiss reactivation.");
    } finally {
      setRowBusyId(null);
    }
  }

  async function handleBulk(action: "approve" | "dismiss") {
    if (!session || selected.size === 0) return;
    setError(null);
    const ids = Array.from(selected);
    try {
      if (action === "approve") {
        await bulkApprovePendingReactivations(session.token, ids);
      } else {
        await bulkDismissPendingReactivations(session.token, ids);
      }
      setPending((prev) => prev?.filter((p) => !selected.has(p.id)) ?? null);
      setSelected(new Set());
    } catch (err) {
      setError(err instanceof ApiError ? err.message : `Could not bulk ${action}.`);
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

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Price Sync</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Propagates supplier price changes onto every promoted Package and turns off items the supplier can no
            longer fulfill (ADR-015). No approval gate on price either direction; a deactivated item only ever comes
            back via the Pending Reactivation review below.
          </p>
        </div>
        <Button disabled={isRunInFlight} onClick={handleTrigger}>
          {isRunInFlight ? "Syncing…" : "Sync All Prices Now"}
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {run && (
        <div className="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-sm font-medium text-gray-800 dark:text-white/90">
            Run #{run.id} —{" "}
            <span
              className={
                run.status === "success"
                  ? "text-success-600 dark:text-success-500"
                  : run.status === "failed"
                    ? "text-error-600 dark:text-error-500"
                    : "text-gray-500 dark:text-gray-400"
              }
            >
              {run.status}
            </span>
          </p>
          {run.status === "failed" && run.error_message && (
            <p className="mt-1 text-sm text-error-600 dark:text-error-400">{run.error_message}</p>
          )}
          {run.status === "success" && run.stats && (
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
              Catalog: {run.stats.catalog_total} total ({run.stats.catalog_created} new, {run.stats.catalog_updated}{" "}
              updated) · {run.stats.price_changed} package{run.stats.price_changed === 1 ? "" : "s"} repriced ·{" "}
              {run.stats.deactivated} deactivated
            </p>
          )}
        </div>
      )}

      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">Pending Reactivation</h2>
        {selected.size > 0 && (
          <div className="flex gap-2">
            <Button size="sm" variant="outline" onClick={() => handleBulk("approve")}>
              Approve All ({selected.size})
            </Button>
            <Button size="sm" variant="outline" onClick={() => handleBulk("dismiss")}>
              Dismiss All ({selected.size})
            </Button>
          </div>
        )}
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="w-10 px-5 py-3">{null}</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Game</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cost</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Reseller Price</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Days Inactive</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {pending?.map((p) => (
                <TableRow key={p.id}>
                  <TableCell className="px-5 py-4">
                    <input type="checkbox" checked={selected.has(p.id)} onChange={() => toggleSelected(p.id)} />
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <span className="font-medium text-gray-800 dark:text-white/90">{p.name}</span>
                    <br />
                    <span className="text-theme-xs text-gray-400">{p.supplier_package_ref} · {p.supplier?.name}</span>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{p.game?.name ?? "—"}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">{formatRm(p.cost_price)}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">{formatRm(p.reseller_cost_price)}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{daysInactive(p.deactivated_at)}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <div className="flex gap-2">
                      <Button size="sm" disabled={rowBusyId === p.id} onClick={() => handleApprove(p.id)}>
                        Approve
                      </Button>
                      <Button size="sm" variant="outline" disabled={rowBusyId === p.id} onClick={() => handleDismiss(p.id)}>
                        Dismiss
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
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
    </div>
  );
}
