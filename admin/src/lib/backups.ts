import { apiFetch, apiDownload } from "@/lib/api-client";

/** ADR-039 2026-10-22 addendum: the two things backed up. */
export type BackupSource = "database" | "openwa";

/**
 * ADR-039: mirrors backend/app/Models/BackupRun.php — one row per backup
 * run (scheduled or manual "Backup Now"), pushed to `/middleware/backups`
 * over the `backups` channel, same shape as `PriceSyncRun`.
 */
export interface BackupRun {
  id: number;
  source: BackupSource;
  status: "queued" | "running" | "success" | "failed";
  triggered_by: string;
  disk: string | null;
  path: string | null;
  size_bytes: number | null;
  restore_test_passed: boolean | null;
  restore_test_details: Record<string, unknown> | null;
  error_message: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
}

export interface BackupRunPage {
  data: BackupRun[];
  current_page: number;
  last_page: number;
  total: number;
}

/**
 * BAK-1: the Backups screen's stat cards.
 */
export interface BackupStats {
  total_count: number;
  total_size_bytes: number;
  /** Latest run per source, so a failing source never hides behind the other's success. */
  last_runs: Record<BackupSource, { status: BackupRun["status"]; at: string } | null>;
}

export function listBackupRuns(page = 1, source: BackupSource | null = null) {
  const params = new URLSearchParams();
  if (page > 1) params.set("page", String(page));
  if (source) params.set("source", source);
  const query = params.size > 0 ? `?${params}` : "";

  return apiFetch<BackupRunPage>(`/api/middleware/backups${query}`);
}

export function getBackupStats() {
  return apiFetch<BackupStats>("/api/middleware/backups/stats");
}

export function triggerBackup(source: BackupSource) {
  return apiFetch<BackupRun>("/api/middleware/backups", { method: "POST", body: { source } });
}

export function deleteBackupRun(runId: number) {
  return apiFetch<null>(`/api/middleware/backups/${runId}`, { method: "DELETE" });
}


/** BAK-4: download a completed archive (auth-gated, so via apiDownload()). */
export async function downloadBackupRun(run: BackupRun): Promise<void> {
  await apiDownload(`/api/middleware/backups/${run.id}/download`, run.path?.split("/").pop() ?? `backup-${run.id}.zip`);
}
