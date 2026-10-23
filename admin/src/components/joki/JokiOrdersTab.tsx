"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { ApiError } from "@/lib/api-client";
import { JOKI_FILTER_LABELS, listJokiOrders, listPenjoki, rm, type JokiBoardPage, type JokiFilter, type Penjoki } from "@/lib/joki";
import { JokiAssignControl } from "@/components/joki/JokiAssignControl";

const FILTERS: (JokiFilter | "all")[] = ["all", "waiting", "in_progress", "stale", "needs_voucher", "done", "failed"];
const STATUS_LABELS: Record<string, string> = { delivered: "Done", failed: "Failed", needs_review: "Needs review" };
const TH = "px-4 py-3 text-start text-theme-xs font-medium text-ink-muted";
const TD = "px-4 py-3 align-top text-theme-sm text-ink-muted";

function since(iso: string | null): string {
  if (!iso) return "—";
  const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60_000));
  if (minutes < 60) return `${minutes}m`;
  if (minutes < 60 * 48) return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;

  return `${Math.floor(minutes / 1440)}d`;
}

/** Positive = time left; negative = overdue. */
function slaLeft(iso: string | null): string {
  if (!iso) return "—";
  const minutes = Math.round((new Date(iso).getTime() - Date.now()) / 60_000);
  const abs = Math.abs(minutes);
  const text = abs < 120 ? `${abs}m` : abs < 2880 ? `${Math.floor(abs / 60)}h` : `${Math.floor(abs / 1440)}d`;

  return minutes >= 0 ? `${text} left` : `${text} overdue`;
}

/**
 * ADR-126 decision 23: the Joki orders board (JokiBoard's filters, the
 * same ones the Dashboard card counts). Oldest paid first. Assign is
 * inline for Pending orders; everything else opens Order Detail.
 */
export function JokiOrdersTab({ initialFilter }: { initialFilter: JokiFilter | "all" }) {
  const [filter, setFilter] = useState<JokiFilter | "all">(initialFilter);
  const [pageNumber, setPageNumber] = useState(1);
  const [page, setPage] = useState<JokiBoardPage | null>(null);
  const [penjoki, setPenjoki] = useState<Penjoki[] | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let current = true;
    Promise.all([listJokiOrders(filter, pageNumber), listPenjoki()])
      .then(([orders, people]) => {
        if (!current) return;
        setPage(orders);
        setPenjoki(people);
        setError(null);
      })
      .catch((err: unknown) => current && setError(err instanceof ApiError ? err.message : "Could not load joki orders."));

    return () => {
      current = false;
    };
  }, [filter, pageNumber, reloadKey]);

  const reload = () => setReloadKey((k) => k + 1);

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800">
          {FILTERS.map((f) => (
            <button
              key={f}
              type="button"
              onClick={() => {
                setFilter(f);
                setPageNumber(1);
              }}
              className={`shrink-0 border-b-2 px-3 py-1.5 text-sm font-medium ${filter === f ? "border-cyan-600 text-cyan-ink" : "border-transparent text-ink-muted hover:text-ink"}`}
            >
              {f === "all" ? "All" : JOKI_FILTER_LABELS[f]}
            </button>
          ))}
        </div>
        <Button size="small" variant="outlined" onClick={reload}>Refresh</Button>
      </div>

      {error && <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}
      {page === null && !error && <p className="text-sm text-ink-muted">Loading…</p>}

      {page && (
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
          <div className="max-w-full overflow-x-auto">
            <table className="min-w-full">
              <thead className="border-b border-gray-100 dark:border-gray-800">
                <tr>
                  <th className={TH}>Order #</th>
                  <th className={TH}>Climb</th>
                  <th className={TH}>Penjoki</th>
                  <th className={TH}>Waited</th>
                  <th className={TH}>Last activity</th>
                  <th className={TH}>SLA</th>
                  <th className={TH}>Sold / payout</th>
                  <th className={TH}>Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {page.data.length === 0 && (
                  <tr><td colSpan={8} className="px-4 py-8 text-center text-sm text-ink-muted">Nothing here.</td></tr>
                )}
                {page.data.map((row) => (
                  <tr key={row.id}>
                    <td className={TD}>
                      <Link href={`/admin/orders?order=${row.id}`} className="whitespace-nowrap font-mono text-code-id font-medium text-ink hover:underline">{row.order_number}</Link>
                      <div className="mt-1 flex flex-wrap gap-1">
                        {row.is_express && <Tag severity="warn">Express</Tag>}
                        {row.is_stale && <Tag severity="danger">Stale</Tag>}
                        {row.delivery_status !== "pending" && <Tag severity={row.delivery_status === "delivered" ? "success" : "danger"}>{STATUS_LABELS[row.delivery_status] ?? row.delivery_status}</Tag>}
                      </div>
                    </td>
                    <td className={`${TD} min-w-[11rem]`}>
                      <span className="text-ink">{row.from_rank ?? "—"} → {row.to_rank ?? "—"}</span>
                      {row.current_rank && <span className="block text-theme-xs">Now {row.current_rank}</span>}
                      {(row.role || row.hero_request) && <span className="block text-theme-xs text-ink-subtle">{[row.role, row.hero_request].filter(Boolean).join(" · ")}</span>}
                    </td>
                    <td className={`${TD} whitespace-nowrap`}>
                      {row.penjoki ?? (row.delivery_status === "pending" ? <span className="text-warning-600 dark:text-warning-400">Waiting</span> : "—")}
                    </td>
                    <td className={`${TD} whitespace-nowrap`}>{since(row.paid_at)}</td>
                    <td className={TD}>{row.last_activity_at ? `${since(row.last_activity_at)} ago` : "—"}</td>
                    <td className={TD}>{slaLeft(row.sla_due_at)}</td>
                    <td className={TD}>{rm(row.selling_price)}<span className="block text-theme-xs">{rm(row.payout_sen)} payout</span></td>
                    <td className={TD}>
                      {row.delivery_status === "pending" ? (
                        <JokiAssignControl orderId={row.id} currentSupplierId={row.penjoki ? row.supplier_id : null} penjoki={penjoki} onDone={reload} />
                      ) : (
                        <Link href={`/admin/orders?order=${row.id}`} className="text-cyan-ink hover:underline">Open</Link>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {page && page.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-ink-muted">
          <span>Page {page.current_page} of {page.last_page} ({page.total} total)</span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={page.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>Previous</Button>
            <Button size="small" variant="outlined" disabled={page.current_page >= page.last_page} onClick={() => setPageNumber((p) => p + 1)}>Next</Button>
          </div>
        </div>
      )}
    </div>
  );
}
