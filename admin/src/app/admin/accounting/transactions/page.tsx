"use client";

/**
 * ADR-083 decision 9 — Transaction Register: read-only, "nothing is
 * ever lost" view across paid orders, supplier funding transfers,
 * supplier REFUND entries, Path-B vouchers, and (2026-09-28 addendum)
 * supplier-transfer corrections, plus a CSV export. Under
 * `/admin/accounting` alongside the Funding Ledger screen (PR-1) — same
 * bookkeeping-not-supplier-integration placement.
 *
 * 2026-09-28 addendum: real backend pagination (was every matching row,
 * unbounded) + a `type` filter + a voided-row tag (figures stay the
 * real originally-recorded ones, never zeroed — the reversal is its own
 * `supplier_adjustment` row).
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
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  getTransactionRegister,
  exportTransactionRegister,
  type TransactionRegisterRow,
  type TransactionRegisterFilters,
} from "@/lib/transaction-register";
import type { Paginated } from "@/lib/payment-settlements";

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400";
const TD = "px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400";

const typeSeverity: Record<TransactionRegisterRow["type"], "info" | "success" | "warn" | "secondary"> = {
  order: "info",
  supplier_transfer: "success",
  supplier_refund: "warn",
  voucher_issued: "secondary",
  supplier_adjustment: "secondary",
  membership_payment: "info",
  reseller_wallet_topup: "success",
  reseller_wallet_refund: "warn",
  withdrawal_payout: "warn",
};

const typeLabel: Record<TransactionRegisterRow["type"], string> = {
  order: "Order",
  supplier_transfer: "Supplier transfer",
  supplier_refund: "Supplier refund",
  voucher_issued: "Voucher issued",
  supplier_adjustment: "Supplier adjustment",
  membership_payment: "Membership payment",
  reseller_wallet_topup: "Reseller wallet top-up",
  reseller_wallet_refund: "Reseller wallet refund",
  withdrawal_payout: "Withdrawal payout",
};

function formatRm(sen: number | null): string {
  return sen === null ? "—" : `RM ${(sen / 100).toFixed(2)}`;
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

export default function TransactionRegisterPage() {
  const router = useRouter();
  const session = useClientSession();
  const token = session?.token ?? null;
  const [page, setPage] = useState<Paginated<TransactionRegisterRow> | null>(null);
  const [pageNo, setPageNo] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [filters, setFilters] = useState<TransactionRegisterFilters>({});
  const [exporting, setExporting] = useState(false);

  function refresh(t: string, f: TransactionRegisterFilters, p: number) {
    return getTransactionRegister(t, { ...f, page: p })
      .then(setPage)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the transaction register."));
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    refresh(s.token, {}, 1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handleFilter() {
    if (!token) return;
    setPageNo(1);
    refresh(token, filters, 1);
  }

  function handlePageChange(p: number) {
    if (!token) return;
    setPageNo(p);
    refresh(token, filters, p);
  }

  async function handleExport() {
    if (!token) return;
    setExporting(true);
    setError(null);
    try {
      await exportTransactionRegister(token, filters);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Export failed.");
    } finally {
      setExporting(false);
    }
  }

  if (error && !page) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!token || !page) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Transaction Register</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Every money-moving event — paid orders, supplier top-ups, supplier refunds, corrections, and compensation
            vouchers — in one place. What the year-end professional works from.
          </p>
        </div>
        <Button variant="outlined" size="small" disabled={exporting} onClick={handleExport}>
          {exporting ? "Exporting…" : "Export CSV"}
        </Button>
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
        <div>
          <Label htmlFor="txn_from">From</Label>
          <Input id="txn_from" type="date" value={filters.from ?? ""} onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value || undefined }))} />
        </div>
        <div>
          <Label htmlFor="txn_to">To</Label>
          <Input id="txn_to" type="date" value={filters.to ?? ""} onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value || undefined }))} />
        </div>
        <div>
          <Label htmlFor="txn_type">Type</Label>
          <select
            id="txn_type"
            value={filters.type ?? ""}
            onChange={(e) => setFilters((f) => ({ ...f, type: (e.target.value || undefined) as TransactionRegisterRow["type"] | undefined }))}
            className="h-11 rounded-lg border border-gray-300 bg-transparent px-3 text-theme-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
          >
            <option value="">All</option>
            {(Object.keys(typeLabel) as TransactionRegisterRow["type"][]).map((t) => (
              <option key={t} value={t}>{typeLabel[t]}</option>
            ))}
          </select>
        </div>
        <Button size="small" onClick={handleFilter}>Filter</Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={page.data} dataKey="reference">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className={TH}>Date</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Type</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Reference</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Description</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Gross</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Fee</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Cost</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Net</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Foreign amount</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const r = item as unknown as TransactionRegisterRow;
                    const isVoided = r.status === "voided";
                    return (
                      <DataTableRow key={r.reference + r.date}>
                        <DataTableCell className={TD}>{formatDate(r.date)}</DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          <Tag severity={typeSeverity[r.type]}>{typeLabel[r.type]}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{r.reference}</DataTableCell>
                        <DataTableCell className={TD}>
                          {r.description}
                          {r.funding_source === "reseller_wallet" && (
                            <Tag severity="secondary" className="ml-2">Wallet-funded</Tag>
                          )}
                        </DataTableCell>
                        <DataTableCell className={`${TD} ${isVoided ? "line-through" : ""}`}>{formatRm(r.gross_sen)}</DataTableCell>
                        <DataTableCell className={`${TD} ${isVoided ? "line-through" : ""}`}>{formatRm(r.fee_sen)}</DataTableCell>
                        <DataTableCell className={TD}>{formatRm(r.cost_sen)}</DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          <span className={isVoided ? "text-gray-400 line-through" : "text-theme-sm text-gray-500 dark:text-gray-400"}>
                            {formatRm(r.net_sen)}
                          </span>
                          {isVoided && <Tag severity="danger" className="ml-2">Voided</Tag>}
                        </DataTableCell>
                        <DataTableCell className={TD}>{r.amount_foreign ? `${r.currency} ${r.amount_foreign}` : "—"}</DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {page.data.length === 0 && (
            <p className="px-5 py-6 text-center text-theme-sm text-gray-400">No transactions in this range.</p>
          )}
        </div>
      </div>

      {page.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-theme-sm text-gray-500 dark:text-gray-400">
          <span>
            Page {page.current_page} of {page.last_page} ({page.total} total)
          </span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={pageNo <= 1} onClick={() => handlePageChange(pageNo - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={pageNo >= page.last_page} onClick={() => handlePageChange(pageNo + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
