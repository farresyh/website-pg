import { apiFetch, ApiError } from "@/lib/api-client";

/**
 * ADR-039: mirrors backend/app/Models/BackupRun.php — one row per
 * `RunDatabaseBackupJob` run (scheduled or manual "Backup Now"),
 * polled by `/middleware/backups` while `queued`/`running`, same shape
 * as `PriceSyncRun`.
 */
export interface BackupRun {
  id: number;
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
  last_run_status: BackupRun["status"] | null;
  last_run_at: string | null;
}

export function listBackupRuns(token: string, page = 1) {
  const query = page > 1 ? `?page=${page}` : "";

  return apiFetch<BackupRunPage>(`/api/middleware/backups${query}`, { token });
}

export function getBackupStats(token: string) {
  return apiFetch<BackupStats>("/api/middleware/backups/stats", { token });
}

export function triggerBackup(token: string) {
  return apiFetch<BackupRun>("/api/middleware/backups", { method: "POST", token });
}

export function deleteBackupRun(token: string, runId: number) {
  return apiFetch<null>(`/api/middleware/backups/${runId}`, { method: "DELETE", token });
}

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * BAK-4: download a completed archive. Not a plain `<a href>` — the
 * route is bearer-token-gated, so this fetches the binary as a Blob
 * with the Authorization header and hands the browser a same-page
 * object-URL download instead.
 */
export async function downloadBackupRun(token: string, run: BackupRun): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/api/middleware/backups/${run.id}/download`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  if (!response.ok) {
    throw new ApiError(response.status, undefined, `Download failed (${response.status})`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = run.path?.split("/").pop() ?? `backup-${run.id}.zip`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
