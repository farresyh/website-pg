"use client";

/**
 * ADR-138 decisions 12 and 14: held supplier prices. The package keeps
 * selling at its current cost; a held drop applies by itself once it has
 * stayed low, and Approve applies it now. "Approve all" is the way out of a
 * mass change that turns out to be real.
 */

import { useState } from "react";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogHeaderActions,
  DialogClose,
  DialogTitle,
  DialogContent,
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
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
  const percent = Math.round(((proposedPrice - oldPrice) / oldPrice) * 100);
  return `${percent > 0 ? "+" : ""}${percent}%`;
}

function bySupplier(items: PendingPriceChange[]) {
  const counts = new Map<number, { name: string; count: number }>();
  for (const item of items) {
    const supplier = item.package.supplier;
    if (!supplier) continue;
    counts.set(supplier.id, { name: supplier.name, count: (counts.get(supplier.id)?.count ?? 0) + 1 });
  }
  return [...counts].map(([id, value]) => ({ id, ...value }));
}

interface Props {
  items: PendingPriceChange[] | null;
  busyId: number | null;
  onApprove: (id: number) => void;
  onApproveAll: (supplierId: number) => Promise<void>;
}

export default function PendingPriceChangeSection({ items, busyId, onApprove, onApproveAll }: Props) {
  const [confirming, setConfirming] = useState<{ id: number; name: string; count: number } | null>(null);
  const [approvingAll, setApprovingAll] = useState(false);

  async function approveAll() {
    if (!confirming) return;
    setApprovingAll(true);
    await onApproveAll(confirming.id);
    setApprovingAll(false);
    setConfirming(null); // a failure shows in the page's own error banner
  }

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-section-title font-semibold text-ink">Held Price Changes</h2>
          <p className="text-theme-xs text-ink-subtle">These packages keep selling at their current cost. A held drop applies by itself once it stays low.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {bySupplier(items ?? []).map((supplier) => (
            <Button key={supplier.id} size="small" variant="outlined" onClick={() => setConfirming(supplier)}>
              Approve all {supplier.name} ({supplier.count})
            </Button>
          ))}
        </div>
      </div>
      <div className="mb-8 overflow-hidden rounded-2xl border border-border bg-surface">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={items ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-border">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Package</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Game</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Current Cost</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Held Price</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Swing</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-border">
                  {({ item }) => {
                    const p = item as unknown as PendingPriceChange;

                    return (
                      <DataTableRow key={p.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <span className="font-medium text-ink">{p.package.name}</span>
                          <br />
                          <span className="text-theme-xs text-ink-subtle">{p.package.supplier_package_ref} · {p.package.supplier?.name}</span>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{p.package.game?.name ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.old_cost_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">{formatRm(p.proposed_cost_price)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-danger-ink">
                          {swingPercent(p.old_cost_price, p.proposed_cost_price)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Button size="small" disabled={busyId === p.id} onClick={() => onApprove(p.id)}>
                            Approve
                          </Button>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {items?.length === 0 && (
            <p className="p-6 text-center text-sm text-ink-muted">
              Nothing held. No supplier price is waiting to apply.
            </p>
          )}
          {items === null && (
            <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>
          )}
        </div>
      </div>
      <Dialog open={confirming !== null} onOpenChange={(e) => { if (!e.value) setConfirming(null); }}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup className="w-full max-w-md">
              <DialogHeader>
                <DialogTitle>Approve all {confirming?.name} prices?</DialogTitle>
                <DialogHeaderActions>
                  <DialogClose aria-label="Close">
                    <CloseIcon className="h-5 w-5" />
                  </DialogClose>
                </DialogHeaderActions>
              </DialogHeader>
              <DialogContent>
                <p className="mb-4 text-sm text-ink-muted">
                  {confirming?.count} packages will change to their held price now. If many moved at once, check the latest deposit first: a mistyped transfer moves every price.
                </p>
                <div className="flex justify-end gap-2">
                  <Button variant="outlined" disabled={approvingAll} onClick={() => setConfirming(null)}>Cancel</Button>
                  <Button disabled={approvingAll} onClick={approveAll}>{approvingAll ? "Approving…" : "Approve all"}</Button>
                </div>
              </DialogContent>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </>
  );
}
