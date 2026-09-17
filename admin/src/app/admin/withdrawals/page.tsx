"use client";

/**
 * WTH-1..5. Only ever operates on the single internal platform owner
 * for MVP — see backend/app/Http/Controllers/Admin/WithdrawalController.php.
 * Maker-checker (WTH-5) is enforced server-side; this page just surfaces
 * whatever error the API returns if a below-permission action is tried.
 */

import { useEffect, useState } from "react";
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
import { Plus as PlusIcon } from "@primeicons/react/plus";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  type Withdrawal,
  type WithdrawalIndexResponse,
  listWithdrawals,
  requestWithdrawal,
  approveWithdrawal,
  rejectWithdrawal,
  completeWithdrawal,
} from "@/lib/withdrawals";
import WithdrawalRequestModal from "@/components/withdrawals/WithdrawalRequestModal";

const STAT_CARDS: { key: keyof WithdrawalIndexResponse["stats"]; label: string }[] = [
  { key: "pending", label: "Pending" },
  { key: "approved", label: "Approved" },
  { key: "completed", label: "Completed" },
  { key: "rejected", label: "Rejected" },
];

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

export default function WithdrawalsPage() {
  const router = useRouter();
  // Read in an effect, not render body — see UserDropdown.tsx for why.
  const session = useClientSession();

  const [data, setData] = useState<WithdrawalIndexResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [actingOnId, setActingOnId] = useState<number | null>(null);

  async function refresh(token: string) {
    try {
      setData(await listWithdrawals(token));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load withdrawals.");
    }
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }

    listWithdrawals(s.token)
      .then(setData)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load withdrawals.");
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleRequestSubmit(values: Parameters<typeof requestWithdrawal>[1]) {
    if (!session) return;
    await requestWithdrawal(session.token, values);
    setIsModalOpen(false);
    await refresh(session.token);
  }

  async function handleAction(action: typeof approveWithdrawal, withdrawal: Withdrawal) {
    if (!session) return;
    setError(null);
    setActingOnId(withdrawal.id);

    try {
      await action(session.token, withdrawal.id);
      await refresh(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setActingOnId(null);
    }
  }

  const statusSeverity: Record<Withdrawal["status"], "warn" | "info" | "success" | "danger"> = {
    pending: "warn",
    approved: "info",
    completed: "success",
    rejected: "danger",
  };

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Withdrawals</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {data ? `Available balance: ${formatRm(data.available_balance)}` : "Review and process withdrawal requests."}
          </p>
        </div>
        <Button size="small" onClick={() => setIsModalOpen(true)}>
          <PlusIcon />
          Request Withdrawal
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        {STAT_CARDS.map(({ key, label }) => (
          <div key={key} className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</p>
            <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">
              {data?.stats[key]?.count ?? 0}
            </p>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              {formatRm(data?.stats[key]?.total ?? 0)}
            </p>
          </div>
        ))}
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={data?.withdrawals ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Amount</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Bank Details</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const w = item as unknown as Withdrawal;

                    return (
                      <DataTableRow key={w.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {new Date(w.created_at).toLocaleString()}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {formatRm(w.amount)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {w.bank_name} — {w.bank_account_no}
                          <br />
                          {w.bank_account_holder}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={statusSeverity[w.status]}>
                            {w.status}
                          </Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex items-center gap-2">
                            {w.status === "pending" && (
                              <>
                                <Button size="small" disabled={actingOnId === w.id} onClick={() => handleAction(approveWithdrawal, w)}>
                                  Approve
                                </Button>
                                <Button size="small" severity="danger" disabled={actingOnId === w.id} onClick={() => handleAction(rejectWithdrawal, w)}>
                                  Reject
                                </Button>
                              </>
                            )}
                            {w.status === "approved" && (
                              <Button size="small" variant="outlined" disabled={actingOnId === w.id} onClick={() => handleAction(completeWithdrawal, w)}>
                                Mark Complete
                              </Button>
                            )}
                            {(w.status === "completed" || w.status === "rejected") && (
                              <span className="text-gray-400">—</span>
                            )}
                          </div>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {data?.withdrawals.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No withdrawal requests yet.</p>
          )}
          {data === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>

      <WithdrawalRequestModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        onSubmit={handleRequestSubmit}
        availableBalance={data?.available_balance ?? 0}
      />
    </div>
  );
}
