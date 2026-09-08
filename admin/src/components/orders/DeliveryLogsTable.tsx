/**
 * ADR-017 decision #4 + Order Journey Logs:
 * Chronological history of delivery attempts for an order, including
 * the initial fulfillment attempt and any subsequent resends.
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
import { CloseIcon } from "@/icons";
import { Send } from "@primeicons/react/send";
import { Refresh } from "@primeicons/react/refresh";
import { Code } from "@primeicons/react/code";
import type { OrderDetail, OrderResendAttempt } from "@/lib/orders";

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

interface DeliveryLogEntry {
  id: string;
  date: string;
  type: "initial" | "resend";
  typeLabel: string;
  packageName: string;
  sku: string;
  priceDiffSen: number | null;
  outcome: string;
  triggeredBy: string | null;
  note: string | null;
  response: Record<string, unknown> | null;
}

function getOutcomeSeverity(outcome: string): "success" | "danger" | "warn" | "info" {
  switch (outcome) {
    case "success":
    case "delivered":
      return "success";
    case "failed":
      return "danger";
    case "needs_review":
    case "pending":
      return "warn";
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

  const resendList = order?.resend_attempts ?? attempts ?? [];
  const entries: DeliveryLogEntry[] = [];

  // 1. Initial delivery attempt
  if (order && (order.delivery_status !== "not_started" || order.paid_at !== null || order.supplier_response !== null)) {
    const hasResends = resendList.length > 0;
    const initialOutcome = hasResends ? "failed" : order.delivery_status;

    let initialNote: string | null = null;
    if (order.supplier_response) {
      const resp = order.supplier_response as Record<string, unknown>;
      if (typeof resp.message === "string") {
        initialNote = resp.message;
      } else if (resp.rc !== undefined) {
        initialNote = `rc: ${String(resp.rc)}`;
      }
    }

    entries.push({
      id: "initial-delivery",
      date: order.paid_at ?? order.created_at,
      type: "initial",
      typeLabel: "Initial Delivery",
      packageName: order.package?.name ?? "—",
      sku: order.supplier_product_ref ?? order.package?.supplier_package_ref ?? "—",
      priceDiffSen: null,
      outcome: initialOutcome,
      triggeredBy: "System (Auto)",
      note: initialNote ?? "Initial checkout fulfillment",
      response: (order.supplier_response as Record<string, unknown>) ?? null,
    });
  }

  // 2. Resend delivery attempts
  for (const attempt of resendList) {
    let attemptNote = attempt.note;
    if (!attemptNote && attempt.supplier_response) {
      const resp = attempt.supplier_response as Record<string, unknown>;
      if (typeof resp.message === "string") {
        attemptNote = resp.message;
      } else if (resp.rc !== undefined) {
        attemptNote = `rc: ${String(resp.rc)}`;
      }
    }

    entries.push({
      id: `resend-${attempt.id}`,
      date: attempt.created_at,
      type: "resend",
      typeLabel: "Resend Delivery",
      packageName: attempt.package?.name ?? order?.package?.name ?? "—",
      sku: attempt.package?.supplier_package_ref ?? order?.supplier_product_ref ?? "—",
      priceDiffSen: attempt.price_diff_sen,
      outcome: attempt.outcome,
      triggeredBy: attempt.triggered_by ?? "Admin",
      note: attemptNote ?? "—",
      response: (attempt.supplier_response as Record<string, unknown>) ?? null,
    });
  }

  return (
    <>
      <div className="mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="border-b border-gray-100 p-6 pb-4 dark:border-gray-800">
          <h2 className="text-sm font-semibold text-gray-800 dark:text-white/90">Delivery & Activity Logs</h2>
          <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Chronological audit trail of all automated and manual supplier delivery attempts.
          </p>
        </div>

        {entries.length === 0 ? (
          <div className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
            No delivery attempts recorded yet. Delivery will be initiated once payment is confirmed.
          </div>
        ) : (
          <div className="max-w-full overflow-x-auto p-6 pt-2">
            <DataTable data={entries} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date & Time</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Event</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package & SKU</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Price Diff</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Outcome</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Note / Reason</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-3 py-2 text-end text-theme-xs font-medium text-gray-500 dark:text-gray-400">Details</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const entry = item as unknown as DeliveryLogEntry;

                      return (
                        <DataTableRow key={entry.id}>
                          <DataTableCell className="px-3 py-3 text-theme-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                            {new Date(entry.date).toLocaleString()}
                          </DataTableCell>

                          <DataTableCell className="px-3 py-3 text-theme-sm">
                            <div className="flex items-center gap-1.5 font-medium text-gray-800 dark:text-white/90">
                              {entry.type === "initial" ? (
                                <Send className="w-3.5 h-3.5 text-primary-500 shrink-0" />
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
                            <div className="font-medium text-gray-800 dark:text-white/90">{entry.packageName}</div>
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
                                      : "text-gray-500 dark:text-gray-400"
                                }
                              >
                                {entry.priceDiffSen > 0 ? "+" : ""}
                                {formatRm(entry.priceDiffSen)}
                              </span>
                            )}
                          </DataTableCell>

                          <DataTableCell className="px-3 py-3 text-theme-sm">
                            <Tag severity={getOutcomeSeverity(entry.outcome)}>
                              {entry.outcome}
                            </Tag>
                          </DataTableCell>

                          <DataTableCell className="px-3 py-3 text-theme-xs text-gray-600 dark:text-gray-300 max-w-xs truncate" title={entry.note ?? undefined}>
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
                  <pre className="rounded-lg bg-gray-100 p-4 font-mono text-xs text-gray-800 dark:bg-white/5 dark:text-gray-200">
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
