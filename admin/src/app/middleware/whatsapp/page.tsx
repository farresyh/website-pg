"use client";

/**
 * ADR-116 2026-11-14 addendum, decision 12 — read-only view of the
 * WhatsApp outbox: every message the app sends, and what happened to it.
 * An "unknown" row may have reached the chat, so it is never re-sent from
 * here. PrimeReact-Tailwind only (ADR-038), the Request Logs pattern.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { SimpleSelect } from "@/components/ui/select";
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
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  type WhatsAppOutboxPage,
  type WhatsAppOutboxRow,
  type WhatsAppOutboxSession,
  type WhatsAppOutboxStatus,
  listWhatsAppOutbox,
} from "@/lib/whatsapp-outbox";

const STATUS_OPTIONS: { label: string; value: string }[] = [
  { label: "All statuses", value: "" },
  { label: "Queued", value: "queued" },
  { label: "Sent", value: "sent" },
  { label: "Failed", value: "failed" },
  { label: "Unknown (check the chat)", value: "unknown" },
  { label: "Expired", value: "expired" },
  { label: "Superseded", value: "superseded" },
  { label: "Skipped", value: "skipped" },
];

const SESSION_OPTIONS: { label: string; value: string }[] = [
  { label: "Both numbers", value: "" },
  { label: "Customer support", value: "customer_support" },
  { label: "Bot (reseller and supplier groups)", value: "bot" },
];

const statusSeverity: Record<WhatsAppOutboxStatus, "success" | "danger" | "warn" | "info" | "secondary"> = {
  sent: "success",
  queued: "info",
  failed: "danger",
  unknown: "warn",
  expired: "secondary",
  superseded: "secondary",
  skipped: "secondary",
};

const HEAD = "px-5 py-3 text-start text-theme-xs font-medium text-ink-muted";
const CELL = "px-5 py-4 text-theme-sm text-ink-muted";

function formatDateTime(value: string | null): string {
  return value ? new Date(value).toLocaleString() : "—";
}

export default function WhatsAppOutboxPage() {
  const router = useRouter();
  const session = useClientSession();
  const [status, setStatus] = useState("");
  const [waSession, setWaSession] = useState("");
  const [pageNumber, setPageNumber] = useState(1);
  const [page, setPage] = useState<WhatsAppOutboxPage | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getClientSession()) router.replace("/login");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!session) return;
    listWhatsAppOutbox({
      status: (status || undefined) as WhatsAppOutboxStatus | undefined,
      session: (waSession || undefined) as WhatsAppOutboxSession | undefined,
      page: pageNumber,
    })
      .then((result) => {
        setError(null);
        setPage(result);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the WhatsApp outbox."));
  }, [session, status, waSession, pageNumber]);

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-page-title font-semibold text-ink">WhatsApp</h1>
        <p className="mt-1 text-sm text-ink-muted">
          Every WhatsApp message the app sends, newest first. &quot;Unknown&quot; means WhatsApp may already have it, so look
          at the chat before sending anything by hand. Message text is not shown here.
        </p>
      </div>

      {error && <p className="mb-4 rounded-lg bg-danger-surface px-4 py-3 text-sm text-danger-ink">{error}</p>}

      <div className="mb-4 flex flex-wrap items-center gap-4">
        <div className="flex items-center gap-2">
          <span className="text-theme-xs text-ink-muted">Status</span>
          <SimpleSelect
            value={status}
            onChange={(v) => {
              setStatus(v);
              setPageNumber(1);
            }}
            options={STATUS_OPTIONS}
            className="min-w-[10rem]"
          />
        </div>
        <div className="flex items-center gap-2">
          <span className="text-theme-xs text-ink-muted">Number</span>
          <SimpleSelect
            value={waSession}
            onChange={(v) => {
              setWaSession(v);
              setPageNumber(1);
            }}
            options={SESSION_OPTIONS}
            className="min-w-[10rem]"
          />
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-border bg-surface">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={page?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead>
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className={HEAD}>Created</DataTableTHeadCell>
                    <DataTableTHeadCell className={HEAD}>Number</DataTableTHeadCell>
                    <DataTableTHeadCell className={HEAD}>Event</DataTableTHeadCell>
                    <DataTableTHeadCell className={HEAD}>To</DataTableTHeadCell>
                    <DataTableTHeadCell className={HEAD}>Order</DataTableTHeadCell>
                    <DataTableTHeadCell className={HEAD}>Status</DataTableTHeadCell>
                    <DataTableTHeadCell className={HEAD}>Tries</DataTableTHeadCell>
                    <DataTableTHeadCell className={HEAD}>Sent</DataTableTHeadCell>
                    <DataTableTHeadCell className={HEAD}>Error</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody>
                  {({ item }) => {
                    const row = item as unknown as WhatsAppOutboxRow;

                    return (
                      <DataTableRow key={row.id}>
                        <DataTableCell className={CELL}>{formatDateTime(row.created_at)}</DataTableCell>
                        <DataTableCell className={CELL}>{row.session === "bot" ? "Bot" : "Customer support"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 font-mono text-theme-xs text-ink">{row.event}</DataTableCell>
                        <DataTableCell className={CELL}>{row.recipient}</DataTableCell>
                        <DataTableCell className={CELL}>{row.order_number ?? "—"}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={statusSeverity[row.status]}>{row.status}</Tag>
                        </DataTableCell>
                        <DataTableCell className={CELL}>{row.attempts}</DataTableCell>
                        <DataTableCell className={CELL}>{formatDateTime(row.sent_at)}</DataTableCell>
                        <DataTableCell className="max-w-xs px-5 py-4 text-theme-xs text-ink-muted">{row.error ?? "—"}</DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>
          {page?.data.length === 0 && <p className="p-6 text-center text-sm text-ink-muted">No messages.</p>}
          {page === null && !error && <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>}
        </div>
      </div>

      {page && page.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-ink-muted">
          <span>
            Page {page.current_page} of {page.last_page} ({page.total} total)
          </span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={page.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={page.current_page >= page.last_page} onClick={() => setPageNumber((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
