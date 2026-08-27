"use client";

/**
 * ADR-039 (BAK-1..5): unified backup run history, stat cards, manual
 * "Backup Now" trigger, download/delete. ADR-038 decision 3/6: this is
 * the first PrimeReact-Tailwind testbed screen — Button/Tag/DataTable/
 * Dialog exclusively, no old TailAdmin primitives on this screen.
 * Restore is deliberately CLI/artisan-only (ADR-039 decision 5) — no
 * restore action exists anywhere on this page.
 */

import React, { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
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
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogTitle,
  DialogContent,
  DialogFooter,
} from "@/components/ui/dialog";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { getEcho } from "@/lib/echo";
import { ApiError } from "@/lib/api-client";
import {
  type BackupRun,
  type BackupRunPage,
  type BackupStats,
  listBackupRuns,
  getBackupStats,
  triggerBackup,
  deleteBackupRun,
  downloadBackupRun,
} from "@/lib/backups";

const statusSeverity: Record<BackupRun["status"], "secondary" | "warn" | "success" | "danger"> = {
  queued: "secondary",
  running: "warn",
  success: "success",
  failed: "danger",
};

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString() : "—";
}

function formatBytes(bytes: number | null): string {
  if (bytes === null) return "—";
  if (bytes < 1024) return `${bytes} B`;
  const units = ["KB", "MB", "GB"];
  let value = bytes / 1024;
  let unitIndex = 0;
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex++;
  }
  return `${value.toFixed(1)} ${units[unitIndex]}`;
}

function filenameOf(run: BackupRun): string {
  return run.path?.split("/").pop() ?? "—";
}

export default function BackupsPage() {
  const router = useRouter();
  const session = useClientSession();

  const [stats, setStats] = useState<BackupStats | null>(null);
  const [historyPage, setHistoryPage] = useState<BackupRunPage | null>(null);
  const [historyPageNumber, setHistoryPageNumber] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [triggering, setTriggering] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [confirmDeleteRun, setConfirmDeleteRun] = useState<BackupRun | null>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refreshStats = useCallback((token: string) => {
    getBackupStats(token)
      .then(setStats)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load backup stats.");
      });
  }, []);

  const refreshHistory = useCallback((token: string, page: number) => {
    listBackupRuns(token, page)
      .then(setHistoryPage)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load backup history.");
      });
  }, []);

  const refreshAll = useCallback(
    (token: string) => {
      refreshStats(token);
      refreshHistory(token, historyPageNumber);
    },
    [refreshStats, refreshHistory, historyPageNumber],
  );

  useEffect(() => {
    if (!session) return;
    refreshAll(session.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session]);

  useEffect(() => {
    if (!session) return;
    refreshHistory(session.token, historyPageNumber);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, historyPageNumber]);

  // ADR-047 decisions 1/3/4 — replaces the 3s "any run in flight" poll
  // with a push subscription on the shared admin-wide `backups` channel.
  // No in-flight gating needed here the way the old poll had — a
  // subscription costs nothing while idle (unlike an interval timer), so
  // this just stays listening for the page's whole lifetime and refetches
  // on whatever change actually happens. If Reverb isn't reachable, the
  // page simply shows its last-loaded state until the admin navigates
  // away and back — the same accepted degrade Price Sync's conversion has.
  useEffect(() => {
    if (!session || !process.env.NEXT_PUBLIC_REVERB_APP_KEY) return;

    const channel = getEcho().private("backups");
    channel.listen(".backup-run.status.updated", () => {
      refreshAll(session.token);
    });

    return () => {
      getEcho().leave("backups");
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session]);

  async function handleTrigger() {
    if (!session) return;
    setError(null);
    setTriggering(true);
    try {
      await triggerBackup(session.token);
      refreshAll(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not start a backup.");
    } finally {
      setTriggering(false);
    }
  }

  async function handleDownload(run: BackupRun) {
    if (!session) return;
    setError(null);
    setBusyId(run.id);
    try {
      await downloadBackupRun(session.token, run);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not download this backup.");
    } finally {
      setBusyId(null);
    }
  }

  async function handleConfirmDelete() {
    if (!session || !confirmDeleteRun) return;
    setError(null);
    setBusyId(confirmDeleteRun.id);
    try {
      await deleteBackupRun(session.token, confirmDeleteRun.id);
      setConfirmDeleteRun(null);
      refreshAll(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not delete this backup.");
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Database Backups</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Full database dump (excluding <code>player_validations</code>, per ADR-021&apos;s PII-retention
            guarantee), encrypted at rest, restore-tested automatically on every run (ADR-039). Restore is
            deliberately CLI/artisan-only — no restore action exists here.
          </p>
        </div>
        <Button disabled={triggering} onClick={handleTrigger}>
          {triggering ? "Starting…" : "Backup Now"}
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {/* BAK-1: stat cards */}
      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Total Backups</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{stats?.total_count ?? "—"}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Total Size</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
            {stats ? formatBytes(stats.total_size_bytes) : "—"}
          </p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Last Run Status</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
            {stats?.last_run_status ? <Tag severity={statusSeverity[stats.last_run_status]}>{stats.last_run_status}</Tag> : "—"}
          </p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Last Run</p>
          <p className="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{formatDateTime(stats?.last_run_at ?? null)}</p>
        </div>
      </div>

      {/* BAK-2: unified run history */}
      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={historyPage?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead>
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Filename</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Type</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Restore Test</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Size</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Created</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Created By</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody>
                  {({ item }) => {
                    const run = item as unknown as BackupRun;

                    return (
                      <DataTableRow key={run.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {filenameOf(run)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {run.triggered_by === "system" ? "Automatic" : "Manual"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={statusSeverity[run.status]}>{run.status}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          {run.restore_test_passed === null ? (
                            "—"
                          ) : run.restore_test_passed ? (
                            <Tag severity="success">passed</Tag>
                          ) : (
                            <Tag severity="danger">failed</Tag>
                          )}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {formatBytes(run.size_bytes)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {formatDateTime(run.created_at)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {run.triggered_by}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex gap-2">
                            <Button
                              size="small"
                              variant="outlined"
                              disabled={busyId === run.id || !run.path}
                              onClick={() => handleDownload(run)}
                            >
                              Download
                            </Button>
                            <Button
                              size="small"
                              variant="outlined"
                              severity="danger"
                              disabled={busyId === run.id}
                              onClick={() => setConfirmDeleteRun(run)}
                            >
                              Delete
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
          {historyPage?.data.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No backups yet.</p>
          )}
          {historyPage === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>

      {historyPage && historyPage.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
          <span>
            Page {historyPage.current_page} of {historyPage.last_page} ({historyPage.total} total)
          </span>
          <div className="flex gap-2">
            <Button
              size="small"
              variant="outlined"
              disabled={historyPage.current_page <= 1}
              onClick={() => setHistoryPageNumber((p) => p - 1)}
            >
              Previous
            </Button>
            <Button
              size="small"
              variant="outlined"
              disabled={historyPage.current_page >= historyPage.last_page}
              onClick={() => setHistoryPageNumber((p) => p + 1)}
            >
              Next
            </Button>
          </div>
        </div>
      )}

      {/* BAK-4 delete confirmation — ADR-039 decision 5: this is the only
          destructive action on this screen; deleting a backup FILE is not
          the same as a restore, which stays CLI/artisan-only. */}
      <Dialog
        open={confirmDeleteRun !== null}
        onOpenChange={(e) => {
          if (!e.value) setConfirmDeleteRun(null);
        }}
      >
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup>
              <DialogHeader>
                <DialogTitle>Delete this backup?</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <p className="text-sm text-gray-600 dark:text-gray-300">
                  Permanently deletes {confirmDeleteRun ? filenameOf(confirmDeleteRun) : "this archive"} from disk.
                  This cannot be undone.
                </p>
              </DialogContent>
              <DialogFooter>
                <Button variant="outlined" onClick={() => setConfirmDeleteRun(null)}>
                  Cancel
                </Button>
                <Button severity="danger" disabled={busyId === confirmDeleteRun?.id} onClick={handleConfirmDelete}>
                  Delete
                </Button>
              </DialogFooter>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </div>
  );
}
