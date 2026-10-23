"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  DataTable,
  DataTableCell,
  DataTableRow,
  DataTableTable,
  DataTableTableContainer,
  DataTableTBody,
  DataTableTHead,
  DataTableTHeadCell,
  DataTableTHeadRow,
} from "@/components/ui/datatable";
import { Button } from "@/components/ui/button";
import { StatusText } from "@/components/orders/StatusText";
import { ApiError } from "@/lib/api-client";
import { listPenjoki, rm, type Penjoki } from "@/lib/joki";
import SupplierTransferModal from "@/components/accounting/SupplierTransferModal";

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-ink-muted";
const TD = "px-5 py-4 text-theme-sm text-ink-muted";

/**
 * ADR-126 decisions 12, 19, 23: each penjoki's open orders and what we owe
 * (their pay-after funding ledger). "Record payment" is the ordinary
 * supplier transfer with a receipt (super admin, like Accounting). A
 * penjoki is added, linked to a WhatsApp group and switched on in
 * Middleware → Suppliers, like any manual supplier.
 */
export function JokiPenjokiTab({ canRecordPayment, reloadKey }: { canRecordPayment: boolean; reloadKey: number }) {
  const [rows, setRows] = useState<Penjoki[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [paying, setPaying] = useState<Penjoki | null>(null);
  const [localReload, setLocalReload] = useState(0);

  useEffect(() => {
    let current = true;
    listPenjoki()
      .then((list) => {
        if (!current) return;
        setRows(list);
        setError(null);
      })
      .catch((err: unknown) => current && setError(err instanceof ApiError ? err.message : "Could not load penjoki."));

    return () => {
      current = false;
    };
  }, [reloadKey, localReload]);

  return (
    <div>
      <p className="mb-4 text-sm text-ink-muted">
        Add a penjoki in <Link href="/middleware/suppliers" className="font-medium text-cyan-ink hover:underline">Middleware → Suppliers</Link>: type Manual, paid after delivery, tick &quot;A penjoki&quot;. Then link their WhatsApp group and switch them on there.
      </p>

      {error && <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={rows ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className={TH}>Penjoki</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Status</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>WhatsApp group</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Open orders</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Owed</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const p = item as unknown as Penjoki;

                    return (
                      <DataTableRow key={p.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-ink">{p.name}</DataTableCell>
                        <DataTableCell className={TD}><StatusText severity={p.is_active ? "success" : "secondary"}>{p.is_active ? "active" : "off"}</StatusText></DataTableCell>
                        <DataTableCell className={TD}><StatusText severity={p.has_group ? "success" : "warn"}>{p.has_group ? "linked" : "not linked"}</StatusText></DataTableCell>
                        <DataTableCell className={TD}>{p.open_orders}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          {p.owed_sen > 0 ? <span className="font-medium text-ink">{rm(p.owed_sen)}</span> : <span className="text-ink-muted">—</span>}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          {canRecordPayment ? (
                            <Button size="small" variant={p.owed_sen > 0 ? undefined : "outlined"} onClick={() => setPaying(p)}>Record payment</Button>
                          ) : (
                            <span className="text-ink-muted">—</span>
                          )}
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {rows?.length === 0 && <p className="p-6 text-center text-sm text-ink-muted">No penjoki yet.</p>}
          {rows === null && !error && <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>}
        </div>
      </div>

      {paying && (
        <SupplierTransferModal
          isOpen
          onClose={() => {
            setPaying(null);
            setLocalReload((k) => k + 1);
          }}
          supplier={{ id: paying.id, name: paying.name, currency: "MYR", funding_mode: "pay_after", is_api_backed: false, balance: null }}
        />
      )}
    </div>
  );
}
