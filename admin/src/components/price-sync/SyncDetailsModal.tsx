"use client";

import React, { useEffect, useState } from "react";
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
import { CloseIcon } from "@/icons";
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
import { ApiError } from "@/lib/api-client";
import { getPriceSyncRunDetails, type SyncDetails, type SyncDetailsGame } from "@/lib/price-sync";

interface SyncDetailsModalProps {
  isOpen: boolean;
  onClose: () => void;
  runId: number | null;
  token: string;
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

/**
 * ADR-016 decision #1/#2: per-game grouped price/cost diffs +
 * deactivations for one Sync History run, fetched fresh on open
 * rather than passed down from the history row — the history table
 * only has the run's own aggregate stats, not the per-game breakdown.
 */
function SyncDetailsContent({ runId, token }: { runId: number; token: string }) {
  const [details, setDetails] = useState<SyncDetails | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getPriceSyncRunDetails(token, runId)
      .then(setDetails)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load sync details.");
      });
  }, [runId, token]);

  return (
    <>
      {error && (
        <p className="mt-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      {!details && !error && <p className="mt-4 text-sm text-gray-500 dark:text-gray-400">Loading…</p>}

      {details && (
        <>
          <p className="mb-4 text-sm text-gray-500 dark:text-gray-400">
            {details.games_touched} game{details.games_touched === 1 ? "" : "s"} touched · {details.run.status}
          </p>

          {details.games.length === 0 && (
            <p className="rounded-lg bg-gray-50 px-3 py-4 text-center text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">
              No price changes or deactivations were recorded for this run.
            </p>
          )}

          {details.games.map((entry) => (
            <div
              key={entry.game?.id ?? "unknown"}
              className="mb-4 overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-800"
            >
              <div className="border-b border-gray-100 bg-gray-50 px-4 py-2 text-sm font-medium text-gray-800 dark:border-gray-800 dark:bg-white/5 dark:text-white/90">
                {entry.game?.name ?? "Unknown game"}
              </div>

              {entry.price_changes.length > 0 && (
                <div className="max-w-full overflow-x-auto">
                  <DataTable data={entry.price_changes} dataKey="package.id">
                    <DataTableTableContainer>
                      <DataTableTable>
                        <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                          <DataTableTHeadRow>
                            <DataTableTHeadCell className="px-4 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-4 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cost</DataTableTHeadCell>
                            <DataTableTHeadCell className="px-4 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Reseller Price</DataTableTHeadCell>
                          </DataTableTHeadRow>
                        </DataTableTHead>
                        <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                          {({ item }) => {
                            const change = item as unknown as SyncDetailsGame["price_changes"][number];

                            return (
                              <DataTableRow key={change.package.id}>
                                <DataTableCell className="px-4 py-2 text-theme-sm text-gray-800 dark:text-white/90">
                                  {change.package.name}
                                </DataTableCell>
                                <DataTableCell className="px-4 py-2 text-theme-sm">
                                  {formatRm(change.old_cost_price)} → {formatRm(change.new_cost_price)}
                                </DataTableCell>
                                <DataTableCell className="px-4 py-2 text-theme-sm">
                                  {formatRm(change.old_standard_selling_price)} → {formatRm(change.new_standard_selling_price)}
                                </DataTableCell>
                              </DataTableRow>
                            );
                          }}
                        </DataTableTBody>
                      </DataTableTable>
                    </DataTableTableContainer>
                  </DataTable>
                </div>
              )}

              {entry.deactivated_packages.length > 0 && (
                <div className="border-t border-gray-100 px-4 py-2 text-sm dark:border-gray-800">
                  <span className="text-gray-500 dark:text-gray-400">Deactivated: </span>
                  <span className="text-gray-800 dark:text-white/90">
                    {entry.deactivated_packages.map((p) => p.name).join(", ")}
                  </span>
                </div>
              )}
            </div>
          ))}
        </>
      )}
    </>
  );
}

export default function SyncDetailsModal({ isOpen, onClose, runId, token }: SyncDetailsModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-3xl">
            <DialogHeader>
              <DialogTitle>Sync Details{runId !== null ? ` — Run #${runId}` : ""}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>
              {isOpen && runId !== null && <SyncDetailsContent key={runId} runId={runId} token={token} />}
            </DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}
