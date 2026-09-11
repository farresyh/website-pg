"use client";

/**
 * ADR-083 decision 2 (PR-1): "Record Supplier Transfer" entry point —
 * pick a supplier, then open its funding ledger (`SupplierTransferModal`).
 * Deliberately under `/admin/accounting`, not `/middleware` — a
 * bookkeeping screen, not supplier-integration config (that stays at
 * `/middleware/suppliers`, ADR-046). Reuses `listSuppliers()` purely as a
 * read-only picker; no supplier CRUD lives here.
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
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { listSuppliers, type Supplier } from "@/lib/suppliers";
import SupplierTransferModal from "@/components/accounting/SupplierTransferModal";

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400";
const TD = "px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400";

export default function AccountingSuppliersPage() {
  const router = useRouter();
  const session = useClientSession();
  const token = session?.token ?? null;
  const [suppliers, setSuppliers] = useState<Supplier[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [ledgerTarget, setLedgerTarget] = useState<Supplier | null>(null);

  function refresh(t: string) {
    return listSuppliers(t)
      .then(setSuppliers)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load suppliers."));
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    refresh(s.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (error && !suppliers) {
    return <p className="rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>;
  }

  if (!token || !suppliers) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Accounting — Supplier Funding</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Record every capital transfer into a supplier&apos;s prepaid account and see its foreign-currency funding
          ledger. Supplier credentials/config stay under Middleware → Suppliers.
        </p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={suppliers} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className={TH}>Supplier</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Currency</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>API-polled balance</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Status</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const s = item as unknown as Supplier;
                    return (
                      <DataTableRow key={s.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {s.name}
                        </DataTableCell>
                        <DataTableCell className={TD}>{s.currency}</DataTableCell>
                        <DataTableCell className={TD}>{s.balance ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          <Tag severity={s.is_active ? "success" : "secondary"}>{s.is_active ? "active" : "inactive"}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4">
                          <Button size="small" variant="outlined" onClick={() => setLedgerTarget(s)}>
                            Funding Ledger
                          </Button>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {suppliers.length === 0 && (
            <p className="px-5 py-6 text-center text-theme-sm text-gray-400">
              No suppliers yet — add one under Middleware → Suppliers first.
            </p>
          )}
        </div>
      </div>

      {ledgerTarget && (
        <SupplierTransferModal
          isOpen={!!ledgerTarget}
          onClose={() => setLedgerTarget(null)}
          token={token}
          supplier={ledgerTarget}
        />
      )}
    </div>
  );
}
