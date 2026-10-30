"use client";

/**
 * WTH-1..5 — reviews the monthly payout tickets the platform opens for
 * affiliates (ADR-132). The platform owner no
 * longer withdraws here (ADR-083 2026-10-10 addendum, decision 17): money
 * leaving to a director is an Envelope Ledger repayment or dividend.
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
import Link from "next/link";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import {
  type Withdrawal,
  type WithdrawalIndexResponse,
  type PayoutRunResponse,
  type PayoutSkip,
  getPayoutRun,
  listWithdrawals,
  approveWithdrawal,
  rejectWithdrawal,
  completeWithdrawal,
} from "@/lib/withdrawals";

const STAT_CARDS: { key: keyof WithdrawalIndexResponse["stats"]; label: string }[] = [
  { key: "pending", label: "Pending" },
  { key: "approved", label: "Approved" },
  { key: "completed", label: "Completed" },
  { key: "rejected", label: "Rejected" },
];

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

const SKIP_LABEL: Record<PayoutSkip, string> = {
  no_bank: "Needs bank details",
  below_min: "Below RM 10 after tier fee",
  open_ticket: "Earlier ticket still Pending",
};

export default function WithdrawalsPage() {
  const router = useRouter();
  // Read in an effect, not render body — see UserDropdown.tsx for why.
  const session = useClientSession();

  const [data, setData] = useState<WithdrawalIndexResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actingOnId, setActingOnId] = useState<number | null>(null);
  const [run, setRun] = useState<PayoutRunResponse | null>(null);
  const [thisMonthOnly, setThisMonthOnly] = useState(false);

  async function refresh() {
    try {
      const [list, worklist] = await Promise.all([listWithdrawals(), getPayoutRun()]);
      setData(list);
      setRun(worklist);
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

    Promise.all([listWithdrawals(), getPayoutRun()])
      .then(([list, worklist]) => {
        setData(list);
        setRun(worklist);
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load withdrawals.");
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function handleAction(
    action: typeof approveWithdrawal,
    withdrawal: Withdrawal,
    messages: { success: string; error: string },
  ) {
    if (!session) return;
    setActingOnId(withdrawal.id);

    try {
      await action(withdrawal.id);
      notify.success(messages.success);
    } catch (err) {
      notify.stickyError(messages.error, err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setActingOnId(null);
    }
    // Refresh after a failed action too — the list must always reflect the
    // server's true state, not a stale row the admin just tried (and failed)
    // to change.
    await refresh();
  }

  const rows = (data?.withdrawals ?? []).filter((w) => !thisMonthOnly || w.payout_period === run?.period);

  const statusSeverity: Record<Withdrawal["status"], "warn" | "info" | "success" | "danger"> = {
    pending: "warn",
    approved: "info",
    completed: "success",
    rejected: "danger",
  };

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Withdrawals</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Payout tickets are opened automatically on the 1st of each month; transfer them by the 7th. Paying a director back, or a dividend, is recorded in the{" "}
          <Link href="/admin/accounting/envelopes" className="text-brand-500 hover:underline">
            Envelope Ledger
          </Link>
          .
        </p>
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

      {run && (run.skipped.length > 0 || run.stale_tickets.length > 0) && (
        <div className="mb-6 rounded-2xl border border-warning-400 bg-warning-50 p-4 text-theme-sm dark:border-warning-500/30 dark:bg-warning-500/10">
          <p className="mb-2 font-medium text-gray-800 dark:text-white/90">Needs attention ({run.period})</p>
          <ul className="flex flex-col gap-1 text-gray-600 dark:text-gray-300">
            {run.stale_tickets.map((t) => (
              <li key={`t${t.id}`}>
                <Tag severity="danger">Stale</Tag> {t.name ?? `Affiliate #${t.affiliate_id}`}: ticket #{t.id} ({formatRm(t.amount)}, {t.payout_period}) is still Pending past the 7th. Approve it, or reject it to roll the
                amount into next month.
              </li>
            ))}
            {run.skipped.map((a) => (
              <li key={`a${a.affiliate_id}`}>
                <Tag severity="warn">No ticket</Tag> {a.name}: {SKIP_LABEL[a.skip]} (balance {formatRm(a.balance)}
                {a.reserve > 0 ? `, ${formatRm(a.reserve)} kept for tier fee` : ""}).
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="mb-3 flex justify-end">
        <Button size="small" variant={thisMonthOnly ? undefined : "outlined"} onClick={() => setThisMonthOnly((v) => !v)}>
          {thisMonthOnly ? "Showing this month" : "This month only"}
        </Button>
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={rows} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Affiliate</DataTableTHeadCell>
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
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-800 dark:text-white/90">
                          {w.affiliate?.name ?? "—"}
                          {w.payout_period && <div className="text-theme-xs text-gray-500 dark:text-gray-400">{w.payout_period}</div>}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {formatRm(w.amount)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {w.bank_name} — {w.bank_account_no}
                          <br />
                          {w.bank_account_holder}
                          {w.bank_details_changed_since_last_approval && (
                            <div className="mt-1">
                              <Tag severity="danger">Bank details changed since last payout</Tag>
                            </div>
                          )}
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
                                <Button size="small" disabled={actingOnId === w.id} onClick={() => handleAction(approveWithdrawal, w, { success: "Withdrawal approved", error: "Failed to approve withdrawal" })}>
                                  Approve
                                </Button>
                                <Button size="small" severity="danger" disabled={actingOnId === w.id} onClick={() => handleAction(rejectWithdrawal, w, { success: "Withdrawal rejected", error: "Failed to reject withdrawal" })}>
                                  Reject
                                </Button>
                              </>
                            )}
                            {w.status === "approved" && (
                              <Button size="small" variant="outlined" disabled={actingOnId === w.id} onClick={() => handleAction(completeWithdrawal, w, { success: "Withdrawal marked complete", error: "Failed to complete withdrawal" })}>
                                Mark Complete
                              </Button>
                            )}
                            {(w.status === "completed" || w.status === "rejected") && (
                              <span className="text-ink-subtle">—</span>
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

          {rows.length === 0 && data !== null && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No payout tickets yet.</p>
          )}
          {data === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>
    </div>
  );
}
