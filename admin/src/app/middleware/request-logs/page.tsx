"use client";

/**
 * ADR-051 (MID-10/11, MUI-9) — read-only viewer over
 * supplier_request_logs: one row per outbound supplier-adapter HTTP
 * call (or circuit-breaker-preempted skip). Payloads shown here are
 * already redacted server-side before they were ever written
 * (SupplierRequestPayloadRedactor) — this screen does no masking of
 * its own. PrimeReact-Tailwind only (ADR-038), same testbed pattern
 * as the Backups screen.
 */

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectIndicator,
  SelectPortal,
  SelectPositioner,
  SelectPopup,
  SelectList,
  SelectOption,
} from "@/components/ui/select";
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
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogTitle,
  DialogContent,
  DialogFooter,
} from "@/components/ui/dialog";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  type SupplierRequestLog,
  type SupplierRequestLogPage,
  type SupplierRequestCallType,
  type SupplierRequestOutcome,
  listRequestLogs,
  getRequestLog,
} from "@/lib/request-logs";
import { type Supplier, listSuppliers } from "@/lib/suppliers";

const CALL_TYPE_OPTIONS: { label: string; value: string }[] = [
  { label: "All call types", value: "" },
  { label: "Check Balance", value: "checkBalance" },
  { label: "List Products", value: "listProducts" },
  { label: "Create Order", value: "createOrder" },
  { label: "Check Status", value: "checkStatus" },
  { label: "Validate Player", value: "validatePlayer" },
];

const OUTCOME_OPTIONS: { label: string; value: string }[] = [
  { label: "All outcomes", value: "" },
  { label: "Success", value: "success" },
  { label: "Failure", value: "failure" },
  { label: "Exception", value: "exception" },
  { label: "Skipped (breaker open)", value: "skipped_breaker_open" },
];

const outcomeSeverity: Record<SupplierRequestOutcome, "success" | "danger" | "warn"> = {
  success: "success",
  failure: "danger",
  exception: "danger",
  skipped_breaker_open: "warn",
};

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString();
}

function FilterSelect({
  label,
  value,
  onChange,
  options,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: { label: string; value: string }[];
}) {
  return (
    <div className="flex items-center gap-2">
      <span className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</span>
      <Select value={value} options={options} optionLabel="label" optionValue="value" onValueChange={(e) => onChange(e.value as string)}>
        <SelectTrigger className="min-w-[10rem]">
          <SelectValue />
          <SelectIndicator />
        </SelectTrigger>
        <SelectPortal>
          <SelectPositioner>
            <SelectPopup>
              <SelectList>
                {options.map((option, index) => (
                  <SelectOption key={option.value} index={index}>
                    {option.label}
                  </SelectOption>
                ))}
              </SelectList>
            </SelectPopup>
          </SelectPositioner>
        </SelectPortal>
      </Select>
    </div>
  );
}

export default function RequestLogsPage() {
  const router = useRouter();
  const session = useClientSession();

  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [supplierId, setSupplierId] = useState("");
  const [callType, setCallType] = useState("");
  const [outcome, setOutcome] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");

  const [page, setPage] = useState<SupplierRequestLogPage | null>(null);
  const [pageNumber, setPageNumber] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [detailLog, setDetailLog] = useState<SupplierRequestLog | null>(null);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!session) return;
    listSuppliers(session.token).then(setSuppliers).catch(() => undefined);
  }, [session]);

  const refresh = useCallback(
    (token: string) => {
      listRequestLogs(token, {
        supplier_id: supplierId ? Number(supplierId) : undefined,
        call_type: (callType || undefined) as SupplierRequestCallType | undefined,
        outcome: (outcome || undefined) as SupplierRequestOutcome | undefined,
        from: from || undefined,
        to: to || undefined,
        page: pageNumber,
      })
        .then(setPage)
        .catch((err: unknown) => {
          setError(err instanceof ApiError ? err.message : "Could not load request logs.");
        });
    },
    [supplierId, callType, outcome, from, to, pageNumber],
  );

  useEffect(() => {
    if (!session) return;
    refresh(session.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, supplierId, callType, outcome, from, to, pageNumber]);

  const supplierOptions = [
    { label: "All suppliers", value: "" },
    ...suppliers.map((s) => ({ label: s.name, value: String(s.id) })),
  ];

  // Fetches the canonical single row rather than reusing the table's
  // own item — the row template's `item` isn't safe to hold onto past
  // its own render cycle (PrimeReact DataTable), and this doubles as
  // the natural place to fetch a heavier payload later if the list
  // endpoint ever stops returning it in full.
  async function handleViewDetail(id: number) {
    if (!session) return;
    setError(null);
    try {
      setDetailLog(await getRequestLog(session.token, id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this request log.");
    }
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Request Logs</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Every outbound supplier-adapter call (or circuit-breaker-preempted skip). Payloads are redacted before
          they&apos;re ever written — credentials never reach this table. Default retention: 30 days (7 days for
          Validate Player).
        </p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-4">
        <FilterSelect
          label="Supplier"
          value={supplierId}
          onChange={(v) => {
            setSupplierId(v);
            setPageNumber(1);
          }}
          options={supplierOptions}
        />
        <FilterSelect
          label="Call type"
          value={callType}
          onChange={(v) => {
            setCallType(v);
            setPageNumber(1);
          }}
          options={CALL_TYPE_OPTIONS}
        />
        <FilterSelect
          label="Outcome"
          value={outcome}
          onChange={(v) => {
            setOutcome(v);
            setPageNumber(1);
          }}
          options={OUTCOME_OPTIONS}
        />
        <div className="flex items-center gap-2">
          <span className="text-theme-xs text-gray-500 dark:text-gray-400">From</span>
          <input
            type="date"
            className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-theme-sm text-gray-800 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90"
            value={from}
            onChange={(e) => {
              setFrom(e.target.value);
              setPageNumber(1);
            }}
          />
        </div>
        <div className="flex items-center gap-2">
          <span className="text-theme-xs text-gray-500 dark:text-gray-400">To</span>
          <input
            type="date"
            className="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-theme-sm text-gray-800 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90"
            value={to}
            onChange={(e) => {
              setTo(e.target.value);
              setPageNumber(1);
            }}
          />
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={page?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead>
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Time</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Supplier</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Call Type</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Outcome</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Duration</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Order</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody>
                  {({ item }) => {
                    const row = item as unknown as SupplierRequestLog;

                    return (
                      <DataTableRow key={row.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {formatDateTime(row.created_at)}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                          {row.supplier?.name ?? "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{row.call_type}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={outcomeSeverity[row.outcome]}>{row.outcome}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {row.status_code ?? "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {row.duration_ms !== null ? `${row.duration_ms}ms` : "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {row.order_id ?? "—"}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Button size="small" variant="outlined" onClick={() => handleViewDetail(row.id)}>
                            View
                          </Button>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {page?.data.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No request logs yet.</p>
          )}
          {page === null && !error && <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>}
        </div>
      </div>

      {page && page.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
          <span>
            Page {page.current_page} of {page.last_page} ({page.total} total)
          </span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={page.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button
              size="small"
              variant="outlined"
              disabled={page.current_page >= page.last_page}
              onClick={() => setPageNumber((p) => p + 1)}
            >
              Next
            </Button>
          </div>
        </div>
      )}

      <Dialog open={detailLog !== null} onOpenChange={(e) => !e.value && setDetailLog(null)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup className="max-w-2xl">
              <DialogHeader>
                <DialogTitle>
                  {detailLog?.supplier?.name ?? "Unknown supplier"} · {detailLog?.call_type}
                </DialogTitle>
              </DialogHeader>
              <DialogContent>
                {detailLog && (
                  <div className="space-y-4 text-theme-sm">
                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <p className="text-theme-xs text-gray-500 dark:text-gray-400">Outcome</p>
                        <Tag severity={outcomeSeverity[detailLog.outcome]}>{detailLog.outcome}</Tag>
                      </div>
                      <div>
                        <p className="text-theme-xs text-gray-500 dark:text-gray-400">Status code</p>
                        <p className="text-gray-800 dark:text-white/90">{detailLog.status_code ?? "—"}</p>
                      </div>
                      <div>
                        <p className="text-theme-xs text-gray-500 dark:text-gray-400">Duration</p>
                        <p className="text-gray-800 dark:text-white/90">{detailLog.duration_ms !== null ? `${detailLog.duration_ms}ms` : "—"}</p>
                      </div>
                      <div>
                        <p className="text-theme-xs text-gray-500 dark:text-gray-400">Order</p>
                        <p className="text-gray-800 dark:text-white/90">{detailLog.order_id ?? "—"}</p>
                      </div>
                    </div>

                    {detailLog.method && detailLog.url && (
                      <div>
                        <p className="text-theme-xs text-gray-500 dark:text-gray-400">Request</p>
                        <p className="break-all font-mono text-theme-xs text-gray-800 dark:text-white/90">
                          {detailLog.method} {detailLog.url}
                        </p>
                      </div>
                    )}

                    {detailLog.error_message && (
                      <div>
                        <p className="text-theme-xs text-gray-500 dark:text-gray-400">Error</p>
                        <p className="text-error-600 dark:text-error-400">{detailLog.error_message}</p>
                      </div>
                    )}

                    {detailLog.request_payload && (
                      <div>
                        <p className="mb-1 text-theme-xs text-gray-500 dark:text-gray-400">Request payload (redacted)</p>
                        <pre className="max-h-48 overflow-auto rounded-lg bg-gray-50 p-3 text-theme-xs dark:bg-white/[0.03]">
                          {JSON.stringify(detailLog.request_payload, null, 2)}
                        </pre>
                      </div>
                    )}

                    {detailLog.response_payload && (
                      <div>
                        <p className="mb-1 text-theme-xs text-gray-500 dark:text-gray-400">Response payload</p>
                        <pre className="max-h-48 overflow-auto rounded-lg bg-gray-50 p-3 text-theme-xs dark:bg-white/[0.03]">
                          {JSON.stringify(detailLog.response_payload, null, 2)}
                        </pre>
                      </div>
                    )}
                  </div>
                )}
              </DialogContent>
              <DialogFooter>
                <Button variant="outlined" onClick={() => setDetailLog(null)}>
                  Close
                </Button>
              </DialogFooter>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </div>
  );
}
