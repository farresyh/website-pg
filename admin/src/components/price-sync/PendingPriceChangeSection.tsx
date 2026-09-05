"use client";

/**
 * ADR-025 decision #8: sixth Price Sync Center section — a supplier
 * price swing large enough to cross the configured threshold, blocked
 * from applying until an admin Approves (recomputes affiliate price
 * from the package's live markup, decision #6) or Dismisses (reverts
 * to the old, already-proven-safe price, decision #7) it here.
 * Extracted from page.tsx to keep that file's own size in check, same
 * reasoning SyncDetailsModal was already split out for.
 */

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
import { Button } from "@/components/ui/button";
import type { PendingPriceChange } from "@/lib/price-sync";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function swingPercent(oldPrice: number, proposedPrice: number): string {
  if (oldPrice === 0) return "—";
  return `${Math.round((Math.abs(proposedPrice - oldPrice) / oldPrice) * 100)}%`;
}

interface Props {
  items: PendingPriceChange[] | null;
  busyId: number | null;
  onApprove: (id: number) => void;
  onDismiss: (id: number) => void;
}

export default function PendingPriceChangeSection({ items, busyId, onApprove, onDismiss }: Props) {
  return (
    <>
      <h2 className="mb-3 text-base font-semibold text-gray-800 dark:text-white/90">Pending Price Change</h2>
      <div className="mb-8 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={items ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Game</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Old Cost</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Proposed Cost</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Swing</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const p = item as unknown as PendingPriceChange;

                    return (
                      <DataTableRow key={p.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <span className="font-medium text-gray-800 dark:text-white/90">{p.package.name}</span>
                          <br />
                          <span className="text-theme-xs text-gray-400">{p.package.supplier_package_ref} · {p.package.supplier?.name}</span>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{p.package.game?.name ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.old_cost_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.proposed_cost_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-error-600 dark:text-error-400">
                          {swingPercent(p.old_cost_price, p.proposed_cost_price)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex gap-2">
                            <Button size="small" disabled={busyId === p.id} onClick={() => onApprove(p.id)}>
                              Approve
                            </Button>
                            <Button size="small" variant="outlined" disabled={busyId === p.id} onClick={() => onDismiss(p.id)}>
                              Dismiss
                            </Button>
                          </div>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {items?.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
              Nothing pending — no supplier price swing has crossed the review threshold.
            </p>
          )}
          {items === null && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>
    </>
  );
}
