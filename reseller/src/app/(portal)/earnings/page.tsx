"use client";

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { getEarnings, type EarningsResponse } from "@/lib/portal";
import { formatRm, formatDateTime, ledgerTypeLabel } from "@/lib/format";
import {
  PageHeader,
  StatCard,
  Panel,
  ErrorNote,
  EmptyRow,
} from "@/components/ui";

export default function EarningsPage() {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<EarningsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;

    let cancelled = false;
    getEarnings(session.token, page)
      .then((result) => {
        if (cancelled) return;
        setData(result);
        setError(null);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(
          err instanceof ApiError ? err.message : "Could not load earnings.",
        );
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [page]);

  const entries = data?.entries;

  return (
    <div>
      <PageHeader
        title="Earnings"
        subtitle="Your margin credits, wholesale-tier fees, and withdrawals."
      />

      {error && <ErrorNote message={error} />}

      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard
          label="Withdrawable balance"
          value={data ? formatRm(data.balance) : "—"}
        />
      </div>

      <Panel>
        <div className="max-w-full overflow-x-auto">
          <table className="min-w-full text-theme-sm">
            <thead className="border-b border-gray-100 dark:border-gray-800">
              <tr className="text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                <th className="px-5 py-3">Date</th>
                <th className="px-5 py-3">Type</th>
                <th className="px-5 py-3">Note</th>
                <th className="px-5 py-3 text-right">Amount</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {entries?.data.map((entry) => (
                <tr key={entry.id} className="text-gray-600 dark:text-gray-300">
                  <td className="px-5 py-4 text-theme-xs text-gray-400">
                    {formatDateTime(entry.created_at)}
                  </td>
                  <td className="px-5 py-4">{ledgerTypeLabel(entry.type)}</td>
                  <td className="px-5 py-4 text-theme-xs text-gray-400">
                    {entry.reason ?? "—"}
                  </td>
                  <td
                    className={`px-5 py-4 text-right font-medium ${
                      entry.amount < 0
                        ? "text-error-600 dark:text-error-500"
                        : "text-success-600 dark:text-success-500"
                    }`}
                  >
                    {entry.amount < 0 ? "" : "+"}
                    {formatRm(entry.amount)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {loading && <EmptyRow>Loading…</EmptyRow>}
          {!loading && entries?.data.length === 0 && (
            <EmptyRow>No ledger entries yet.</EmptyRow>
          )}
        </div>
      </Panel>

      {entries && entries.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-theme-sm text-gray-500 dark:text-gray-400">
          <span>
            Page {entries.current_page} of {entries.last_page}
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={entries.current_page <= 1}
              onClick={() => {
                setLoading(true);
                setPage((p) => Math.max(1, p - 1));
              }}
              className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs disabled:opacity-40 dark:border-gray-700"
            >
              Previous
            </button>
            <button
              type="button"
              disabled={entries.current_page >= entries.last_page}
              onClick={() => {
                setLoading(true);
                setPage((p) => p + 1);
              }}
              className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs disabled:opacity-40 dark:border-gray-700"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
