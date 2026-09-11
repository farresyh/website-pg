"use client";

/**
 * ADR-083 decision 9 — Transaction Register: read-only, "nothing is ever
 * lost" view across paid orders, supplier funding transfers, supplier
 * REFUND entries, and Path-B vouchers, plus a CSV export. Under
 * `/admin/accounting` alongside the Funding Ledger screen (PR-1) — same
 * bookkeeping-not-supplier-integration placement.
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

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400";
const TD = "px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400";

const typeSeverity: Record<TransactionRegisterRow["type"], "info" | "success" | "warn" | "secondary"> = {
  order: "info",
  supplier_transfer: "success",
  supplier_refund: "warn",
  voucher_issued: "secondary",
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
  const [rows, setRows] = useState<TransactionRegisterRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [filters, setFilters] = useState<TransactionRegisterFilters>({});
  const [exporting, setExporting] = useState(false);

  function refresh(t: string, f: TransactionRegisterFilters) {
    return getTransactionRegister(t, f)
      .then((res) => setRows(res.rows))
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the transaction register."));
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    refresh(s.token, {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function handleFilter() {
    if (!token) return;
    refresh(token, filters);
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

  if (error && !rows) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!token || !rows) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Transaction Register</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Every money-moving event — paid orders, supplier top-ups, supplier refunds, and compensation vouchers —
            in one place. What the year-end professional works from.
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
        <Button size="small" onClick={handleFilter}>Filter</Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={rows} dataKey="reference">
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
                    return (
                      <DataTableRow key={r.reference + r.date}>
                        <DataTableCell className={TD}>{formatDate(r.date)}</DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          <Tag severity={typeSeverity[r.type]}>{r.type.replace("_", " ")}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{r.reference}</DataTableCell>
                        <DataTableCell className={TD}>{r.description}</DataTableCell>
                        <DataTableCell className={TD}>{formatRm(r.gross_sen)}</DataTableCell>
                        <DataTableCell className={TD}>{formatRm(r.fee_sen)}</DataTableCell>
                        <DataTableCell className={TD}>{formatRm(r.cost_sen)}</DataTableCell>
                        <DataTableCell className={TD}>{formatRm(r.net_sen)}</DataTableCell>
                        <DataTableCell className={TD}>{r.amount_foreign ? `${r.currency} ${r.amount_foreign}` : "—"}</DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {rows.length === 0 && (
            <p className="px-5 py-6 text-center text-theme-sm text-gray-400">No transactions in this range.</p>
          )}
        </div>
      </div>
    </div>
  );
}
