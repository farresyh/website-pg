"use client";

/** ADR-110 PR-B — one settlement batch's own matched/unmatched CHIP transactions. */

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
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
import { ApiError } from "@/lib/api-client";
import {
  type PaymentSettlement,
  type ChipSettledTransaction,
  type Paginated,
  type PaidButNotSettledRow,
  getPaymentSettlement,
} from "@/lib/payment-settlements";

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400";
const TD = "px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400";

function rm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

export default function PaymentSettlementDetailPage() {
  const router = useRouter();
  const session = useClientSession();
  const params = useParams<{ id: string }>();
  const settlementId = Number(params.id);

  const [settlement, setSettlement] = useState<PaymentSettlement | null>(null);
  const [transactions, setTransactions] = useState<Paginated<ChipSettledTransaction> | null>(null);
  const [paidButNotSettled, setPaidButNotSettled] = useState<PaidButNotSettledRow[]>([]);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    getPaymentSettlement(s.token, settlementId, page)
      .then((res) => {
        setSettlement(res.settlement);
        setTransactions(res.transactions);
        setPaidButNotSettled(res.paid_but_not_settled);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load this settlement."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settlementId, page]);

  if (error) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!session || !settlement || !transactions) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6">
        <Link href="/admin/accounting/settlements" className="text-theme-sm text-brand-500 hover:underline">
          ← Back to CHIP Settlements
        </Link>
        <h1 className="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">
          Settlement {settlement.date_from} → {settlement.date_to}
        </h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Uploaded from {settlement.original_filename}. Expected net {rm(settlement.expected_net_sen)}, CHIP&apos;s own
          file reports net {rm(settlement.file_net_sen)}.
        </p>
      </div>

      {paidButNotSettled.length > 0 && (
        <div className="mb-6 rounded-lg bg-warning-50 p-4 text-theme-sm dark:bg-warning-500/15">
          <p className="font-medium text-warning-700 dark:text-warning-400">
            {paidButNotSettled.length} paid-but-not-yet-settled — likely why Expected Net is higher than File/Bank
          </p>
          <p className="mt-1 text-warning-600 dark:text-warning-400">
            CHIP settles T+1/T+2 — these were charged inside this window but hadn&apos;t yet appeared in a settlement
            file when this one was pulled. Not a discrepancy: they should show up in a later upload once CHIP
            actually settles them.
          </p>
          <ul className="mt-2 space-y-1">
            {paidButNotSettled.map((row) => (
              <li key={row.reference} className="flex justify-between text-warning-700 dark:text-warning-400">
                <span>{row.reference}</span>
                <span>{rm(row.amount_sen)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={transactions.data} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className={TH}>Transaction ID</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Matched To</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Amount</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Fee</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Net</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Acquirer</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Settled On</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const t = item as unknown as ChipSettledTransaction;
                    return (
                      <DataTableRow key={t.id}>
                        <DataTableCell className="px-5 py-4 font-mono text-theme-xs text-gray-800 dark:text-white/90">{t.transaction_id}</DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          {t.matched_type ? (
                            <Tag severity="success">{t.matched_type.replace(/_/g, " ")} #{t.matched_id}</Tag>
                          ) : (
                            <Tag severity="warn">unmatched</Tag>
                          )}
                        </DataTableCell>
                        <DataTableCell className={TD}>{rm(t.amount_sen)}</DataTableCell>
                        <DataTableCell className={TD}>{rm(t.fee_sen)}</DataTableCell>
                        <DataTableCell className={TD}>{rm(t.net_amount_sen)}</DataTableCell>
                        <DataTableCell className={TD}>{t.acquirer}</DataTableCell>
                        <DataTableCell className={TD}>{t.settled_on}</DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {transactions.data.length === 0 && (
            <p className="px-5 py-6 text-center text-theme-sm text-gray-400">No transactions recorded for this settlement.</p>
          )}
        </div>
      </div>

      {transactions.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-theme-sm text-gray-500 dark:text-gray-400">
          <span>
            Page {transactions.current_page} of {transactions.last_page} ({transactions.total} total)
          </span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              Previous
            </Button>
            <Button
              size="small"
              variant="outlined"
              disabled={page >= transactions.last_page}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
