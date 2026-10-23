"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { ApiError } from "@/lib/api-client";
import { listPenjoki, rm, type Penjoki } from "@/lib/joki";
import SupplierTransferModal from "@/components/accounting/SupplierTransferModal";

const TH = "px-4 py-3 text-start text-theme-xs font-medium text-ink-muted";
const TD = "px-4 py-3 text-theme-sm text-ink-muted";

/**
 * ADR-126 decisions 12, 19, 23: each penjoki's open orders and what we owe
 * (their pay-after funding ledger). "Record payment" is the ordinary
 * supplier transfer with a receipt (super admin, like Accounting). A
 * penjoki is added, linked to a WhatsApp group and switched on in
 * Middleware → Suppliers, like any manual supplier.
 */
export function JokiPenjokiTab({ canRecordPayment }: { canRecordPayment: boolean }) {
  const [rows, setRows] = useState<Penjoki[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [paying, setPaying] = useState<Penjoki | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let current = true;
    listPenjoki()
      .then((list) => current && (setRows(list), setError(null)))
      .catch((err: unknown) => current && setError(err instanceof ApiError ? err.message : "Could not load penjoki."));

    return () => {
      current = false;
    };
  }, [reloadKey]);

  return (
    <div>
      <p className="mb-4 text-sm text-ink-muted">
        Add a penjoki in <Link href="/middleware/suppliers" className="text-cyan-ink hover:underline">Middleware → Suppliers</Link> (Manual, pay after delivery, tick &quot;A penjoki&quot;), then link their WhatsApp group and switch them on there.
      </p>

      {error && <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}
      {rows === null && !error && <p className="text-sm text-ink-muted">Loading…</p>}

      {rows && (
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
          <div className="max-w-full overflow-x-auto">
            <table className="min-w-full">
              <thead className="border-b border-gray-100 dark:border-gray-800">
                <tr>
                  <th className={TH}>Penjoki</th>
                  <th className={TH}>Status</th>
                  <th className={TH}>Open orders</th>
                  <th className={TH}>Owed</th>
                  <th className={TH}>Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {rows.length === 0 && <tr><td colSpan={5} className="px-4 py-8 text-center text-sm text-ink-muted">No penjoki yet.</td></tr>}
                {rows.map((p) => (
                  <tr key={p.id}>
                    <td className={`${TD} text-ink`}>{p.name}</td>
                    <td className={TD}>
                      <div className="flex flex-wrap gap-1">
                        <Tag severity={p.is_active ? "success" : "secondary"}>{p.is_active ? "Active" : "Off"}</Tag>
                        {!p.has_group && <Tag severity="warn">No group</Tag>}
                      </div>
                    </td>
                    <td className={TD}>{p.open_orders}</td>
                    <td className={TD}>{p.owed_sen > 0 ? <span className="text-ink">{rm(p.owed_sen)}</span> : "—"}</td>
                    <td className={TD}>
                      {canRecordPayment ? <Button size="small" variant="outlined" onClick={() => setPaying(p)}>Record payment</Button> : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {paying && (
        <SupplierTransferModal
          isOpen
          onClose={() => {
            setPaying(null);
            setReloadKey((k) => k + 1);
          }}
          supplier={{ id: paying.id, name: paying.name, currency: "MYR", funding_mode: "pay_after", is_api_backed: false, balance: null }}
        />
      )}
    </div>
  );
}
