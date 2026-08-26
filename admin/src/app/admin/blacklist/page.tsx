"use client";

/**
 * ADR-007 / FRAUD-1..3. Independent of any supplier-side blacklist —
 * checked at checkout (CheckoutController::assertNotBlacklisted())
 * before payment or supplier submission (prd.md §7.1 step 5).
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Badge from "@/components/ui/badge/Badge";
import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
import { useClientSession } from "@/hooks/useClientSession";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import {
  type BlacklistEntry,
  type BlacklistIndexResponse,
  listBlacklistEntries,
  createBlacklistEntry,
  deactivateBlacklistEntry,
} from "@/lib/blacklist";
import CreateBlacklistEntryModal from "@/components/blacklist/CreateBlacklistEntryModal";
import BlacklistHitsModal from "@/components/blacklist/BlacklistHitsModal";

const TYPE_LABEL: Record<BlacklistEntry["type"], string> = {
  player_id: "Player ID",
  email: "Email",
  phone: "Phone",
};

export default function BlacklistPage() {
  const router = useRouter();
  const session = useClientSession();

  const [data, setData] = useState<BlacklistIndexResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [deactivatingId, setDeactivatingId] = useState<number | null>(null);
  const [historyEntryId, setHistoryEntryId] = useState<number | null>(null);

  async function refresh(token: string) {
    try {
      setData(await listBlacklistEntries(token));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load blacklist entries.");
    }
  }

  useEffect(() => {
    // A plain function call, not the useClientSession() hook above: this
    // effect needs an immediate, authoritative read the moment it runs
    // (real browser/sessionStorage, no SSR/hydration snapshot involved),
    // not the hook's hydration-safe-but-eventually-consistent value —
    // using the hook here raced its own resync on a hard navigation and
    // fired a false redirect while a valid session existed, caught live.
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }

    listBlacklistEntries(s.token)
      .then(setData)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load blacklist entries.");
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleCreate(values: Parameters<typeof createBlacklistEntry>[1]) {
    if (!session) return;
    await createBlacklistEntry(session.token, values);
    setIsModalOpen(false);
    await refresh(session.token);
  }

  async function handleDeactivate(entry: BlacklistEntry) {
    if (!session) return;
    setError(null);
    setDeactivatingId(entry.id);

    try {
      await deactivateBlacklistEntry(session.token, entry.id);
      await refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not deactivate entry.");
    } finally {
      setDeactivatingId(null);
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Blacklist</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Internal fraud blacklist — blocks checkout independent of any supplier-side blacklist.
          </p>
        </div>
        <Button size="sm" startIcon={<PlusIcon />} onClick={() => setIsModalOpen(true)}>
          Add Entry
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Active</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{data?.stats.active ?? 0}</p>
        </div>
        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">Total</p>
          <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{data?.stats.total ?? 0}</p>
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Type</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Value</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Reason</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Added By</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Blocked Attempts</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {data?.entries.map((entry) => (
                <TableRow key={entry.id}>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{TYPE_LABEL[entry.type]}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{entry.value}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{entry.reason}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{entry.creator?.name ?? "—"}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <button
                      type="button"
                      onClick={() => setHistoryEntryId(entry.id)}
                      className="text-brand-500 hover:underline"
                    >
                      {entry.hits_count ?? 0}
                    </button>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <Badge size="sm" color={entry.is_active ? "success" : "light"}>
                      {entry.is_active ? "Active" : "Inactive"}
                    </Badge>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    {entry.is_active ? (
                      <Button
                        size="sm"
                        variant="danger"
                        disabled={deactivatingId === entry.id}
                        onClick={() => handleDeactivate(entry)}
                      >
                        Remove
                      </Button>
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          {data?.entries.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No blacklist entries yet.</p>
          )}
          {data === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>

      <CreateBlacklistEntryModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} onSubmit={handleCreate} />
      {session && (
        <BlacklistHitsModal entryId={historyEntryId} token={session.token} onClose={() => setHistoryEntryId(null)} />
      )}
    </div>
  );
}
