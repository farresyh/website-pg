/**
 * ADR-017 decision #4 + Order Journey Logs:
 * Chronological history of delivery attempts for an order, including
 * the initial fulfillment attempt and any subsequent resends.
 *
 * ADR-106 decision 5: once a real `attempt_type=initial` row exists
 * for an order, the "Initial Delivery" row is built from IT — real
 * timestamp, real package/SKU, real response — instead of being
 * synthesized live from Order.supplier_response/delivery_status (the
 * 2026-09-15 bugfix's own honest fallback). That fallback is kept
 * exactly as-is for any order with no `initial` row (created before
 * this shipped) — its true initial data is already unrecoverably
 * gone, nothing to switch to. `manual_confirm` rows (decision 3, from
 * markDeliveredManually()) render alongside `resend` rows in the same
 * history list, their own icon/label.
 */
import { useState } from "react";
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
import { Send } from "@primeicons/react/send";
import { Refresh } from "@primeicons/react/refresh";
import { Verified } from "@primeicons/react/verified";
import { Code } from "@primeicons/react/code";
import type { OrderDetail, OrderResendAttempt } from "@/lib/orders";
import { buildDeliveryLogEntries, type DeliveryLogEntry } from "@/lib/delivery-log-entries";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

function getOutcomeSeverity(outcome: string): "success" | "danger" | "warn" | "info" | "review" {
  switch (outcome) {
    case "success":
    case "delivered":
      return "success";
    case "failed":
    // ADR-105 2026-10-06 decision 16 — a resend refused before it reached
    // the supplier; its note says why.
    case "rejected":
      return "danger";
    // ADR-104 decision 3 — the artifact's own distinct review token,
    // same reasoning as the order-level delivery_status map.
    case "needs_review":
      return "review";
    case "pending":
    case "partially_delivered":
      return "warn";
    // 2026-09-15 bugfix — the Initial Delivery row's own honest fallback
    // once its real response is no longer recoverable (see the comment
    // where this row is built below).
    case "unknown":
    case "processing":
    default:
      return "info";
  }
}

interface DeliveryLogsTableProps {
  order?: OrderDetail;
  attempts?: OrderResendAttempt[];
}

export default function DeliveryLogsTable({ order, attempts = [] }: DeliveryLogsTableProps) {
  const [activeResponse, setActiveResponse] = useState<{ title: string; data: Record<string, unknown> } | null>(null);

  const entries = buildDeliveryLogEntries(order, attempts);

  return (
    <>
      <div className="mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
        <div className="border-b border-gray-100 p-6 pb-4 dark:border-gray-800">
          <h2 className="text-section-title font-semibold text-ink">Delivery & Activity Logs</h2>
          <p className="mt-1 text-xs text-ink-muted">
            Chronological audit trail of all automated and manual supplier delivery attempts.
          </p>
        </div>

        {entries.length === 0 ? (
          <div className="p-6 text-center text-sm text-ink-muted">
            No delivery attempts recorded yet. Delivery will be initiated once payment is confirmed.
          </div>
        ) : (
          <div className="max-w-full overflow-x-auto p-6 pt-2">
            <DataTable data={entries} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Date & Time</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Event</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Package & SKU</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Price Diff</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Outcome</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-ink-muted">Note / Reason</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-end text-theme-xs font-medium text-ink-muted">Details</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const entry = item as unknown as DeliveryLogEntry;

                      return (
                        <DataTableRow key={entry.id}>
                          <DataTableCell className="px-3 py-3 text-theme-xs text-ink-muted whitespace-nowrap">
                            {new Date(entry.date).toLocaleString()}
                          </DataTableCell>

                          <DataTableCell className="px-3 py-3 text-theme-sm">
                            <div className="flex items-center gap-1.5 font-medium text-ink">
                              {entry.type === "initial" ? (
                                <Send className="w-3.5 h-3.5 text-primary-500 shrink-0" />
                              ) : entry.type === "manual_confirm" ? (
                                <Verified className="w-3.5 h-3.5 text-success-500 shrink-0" />
                              ) : (
                                <Refresh className="w-3.5 h-3.5 text-amber-500 shrink-0" />
                              )}
                              <span>{entry.typeLabel}</span>
                            </div>
                            {entry.triggeredBy && (
                              <div className="text-theme-xs text-gray-400">
                                {entry.triggeredBy}
                              </div>
                            )}
                          </DataTableCell>

                          <DataTableCell className="px-3 py-3 text-theme-sm">
                            <div className="font-medium text-ink">{entry.packageName}</div>
                            <div className="font-mono text-theme-xs text-gray-400">{entry.sku}</div>
                          </DataTableCell>

                          <DataTableCell className="px-3 py-3 text-theme-sm whitespace-nowrap">
                            {entry.priceDiffSen === null ? (
                              <span className="text-gray-400">—</span>
                            ) : (
                              <span
                                className={
                                  entry.priceDiffSen > 0
                                    ? "font-medium text-error-600 dark:text-error-400"
                                    : entry.priceDiffSen < 0
                                      ? "font-medium text-success-600 dark:text-success-400"
                                      : "text-ink-muted"
                                }
                              >
                                {entry.priceDiffSen > 0 ? "+" : ""}
                                {formatRm(entry.priceDiffSen)}
                              </span>
                            )}
                          </DataTableCell>

                          <DataTableCell className="px-3 py-3 text-theme-sm">
                            <Tag dot severity={getOutcomeSeverity(entry.outcome)}>
                              {entry.outcome}
                            </Tag>
                          </DataTableCell>

                          <DataTableCell className="px-3 py-3 text-theme-xs text-ink-muted max-w-xs truncate" title={entry.note ?? undefined}>
                            {entry.note ?? "—"}
                          </DataTableCell>

                          <DataTableCell className="px-3 py-3 text-theme-sm text-end">
                            {entry.response ? (
                              <Button
                                size="small"
                                variant="outlined"
                                onClick={() => setActiveResponse({ title: `${entry.typeLabel} Response`, data: entry.response! })}
                              >
                                <Code className="w-3.5 h-3.5 mr-1" />
                                View JSON
                              </Button>
                            ) : (
                              <span className="text-theme-xs text-gray-400">—</span>
                            )}
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
      </div>

      <Dialog
        open={activeResponse !== null}
        onOpenChange={(e) => {
          if (!e.value) setActiveResponse(null);
        }}
      >
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup className="w-full max-w-2xl">
              <DialogHeader>
                <DialogTitle>{activeResponse?.title ?? "Supplier Response"}</DialogTitle>
                <DialogHeaderActions>
                  <DialogClose aria-label="Close" onClick={() => setActiveResponse(null)}>
                    <CloseIcon className="h-5 w-5" />
                  </DialogClose>
                </DialogHeaderActions>
              </DialogHeader>
              <DialogContent>
                <div className="max-h-96 overflow-y-auto">
                  <pre className="rounded-lg bg-subtle p-4 font-mono text-xs text-ink">
                    {JSON.stringify(activeResponse?.data, null, 2)}
                  </pre>
                </div>
              </DialogContent>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </>
  );
}
