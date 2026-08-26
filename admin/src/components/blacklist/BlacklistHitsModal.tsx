"use client";

import { useEffect, useState } from "react";
import { Modal } from "@/components/ui/modal";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import { ApiError } from "@/lib/api-client";
import { getBlacklistEntry, type BlacklistEntryDetail } from "@/lib/blacklist";

interface BlacklistHitsModalProps {
  entryId: number | null;
  token: string;
  onClose: () => void;
}

/**
 * FRAUD-3: "view history of orders blocked by a given entry." A
 * blocked attempt never creates an Order (FRAUD-2 rejects before
 * payment), so this renders blacklist_hits, not an orders list.
 */
function BlacklistHitsContent({ entryId, token }: { entryId: number; token: string }) {
  const [detail, setDetail] = useState<BlacklistEntryDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getBlacklistEntry(token, entryId)
      .then(setDetail)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load blocked-attempt history.");
      });
  }, [entryId, token]);

  return (
    <div className="max-h-[80vh] overflow-y-auto p-6">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">
        Blocked Attempts{detail ? ` — ${detail.value}` : ""}
      </h3>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        Every checkout attempt this entry blocked before payment or supplier submission.
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {detail === null && !error && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
      )}

      {detail && detail.hits.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">No blocked attempts recorded yet.</p>
      )}

      {detail && detail.hits.length > 0 && (
        <div className="overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-800">
          <div className="max-w-full overflow-x-auto">
            <Table>
              <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                <TableRow>
                  <TableCell isHeader className="px-4 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Player ID</TableCell>
                  <TableCell isHeader className="px-4 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Email</TableCell>
                  <TableCell isHeader className="px-4 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Phone</TableCell>
                  <TableCell isHeader className="px-4 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">IP</TableCell>
                  <TableCell isHeader className="px-4 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">When</TableCell>
                </TableRow>
              </TableHeader>
              <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                {detail.hits.map((hit) => (
                  <TableRow key={hit.id}>
                    <TableCell className="px-4 py-3 text-theme-sm text-gray-700 dark:text-gray-300">{hit.player_id ?? "—"}</TableCell>
                    <TableCell className="px-4 py-3 text-theme-sm text-gray-700 dark:text-gray-300">{hit.customer_email ?? "—"}</TableCell>
                    <TableCell className="px-4 py-3 text-theme-sm text-gray-700 dark:text-gray-300">{hit.customer_phone ?? "—"}</TableCell>
                    <TableCell className="px-4 py-3 text-theme-sm text-gray-700 dark:text-gray-300">{hit.ip ?? "—"}</TableCell>
                    <TableCell className="px-4 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{new Date(hit.created_at).toLocaleString()}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </div>
      )}
    </div>
  );
}

export default function BlacklistHitsModal({ entryId, token, onClose }: BlacklistHitsModalProps) {
  return (
    <Modal isOpen={entryId !== null} onClose={onClose} className="max-w-2xl">
      {entryId !== null && <BlacklistHitsContent key={entryId} entryId={entryId} token={token} />}
    </Modal>
  );
}
