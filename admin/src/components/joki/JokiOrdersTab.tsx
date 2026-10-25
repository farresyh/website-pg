"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Clock } from "@primeicons/react/clock";
import { Refresh } from "@primeicons/react/refresh";
import { Ticket } from "@primeicons/react/ticket";
import { List } from "@primeicons/react/list";
import type { ComponentType } from "react";
import {
  DataTable,
  DataTableCell,
  DataTableRow,
  DataTableTable,
  DataTableTableContainer,
  DataTableTBody,
  DataTableTHead,
  DataTableTHeadCell,
  DataTableTHeadRow,
} from "@/components/ui/datatable";
import {
  Dialog,
  DialogBackdrop,
  DialogClose,
  DialogContent,
  DialogHeader,
  DialogHeaderActions,
  DialogPopup,
  DialogPortal,
  DialogPositioner,
  DialogTitle,
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { OptionSelect } from "@/components/ui/option-select";
import { useDebouncedValue } from "@/hooks/useDebouncedValue";
import { DATE_RANGE_PRESETS, type DateRangePreset, resolveDateRange } from "@/lib/date-range";
import { Tag } from "@/components/ui/tag";
import { StatusText, deliveryStatusSeverity } from "@/components/orders/StatusText";
import { ApiError } from "@/lib/api-client";
import type { OrderListItem } from "@/lib/orders";
import { JOKI_FILTER_LABELS, getJokiCounts, listJokiOrders, listPenjoki, rm, type Penjoki, type JokiBoardPage, type JokiBoardRow, type JokiCounts, type JokiFilter } from "@/lib/joki";
import { JokiAssignControl } from "@/components/joki/JokiAssignControl";

const FILTERS: (JokiFilter | "all")[] = ["all", "waiting", "in_progress", "stale", "needs_voucher", "done", "failed"];
const TH = "px-5 py-3 text-start text-theme-xs font-medium text-ink-muted";
const TD = "px-5 py-4 text-theme-sm text-ink-muted";

/** The same card treatment as Orders' OrderSummaryCards: "Waiting" carries the permanent purple marker. */
const CARDS: { value: JokiFilter | "all"; key: keyof JokiCounts | "all"; icon: ComponentType<{ className?: string }> | null }[] = [
  { value: "waiting", key: "waiting", icon: null },
  { value: "in_progress", key: "in_progress", icon: Refresh },
  { value: "stale", key: "stale", icon: Clock },
  { value: "needs_voucher", key: "needs_voucher", icon: Ticket },
  { value: "all", key: "all", icon: List },
];

function duration(minutes: number): string {
  const abs = Math.abs(minutes);
  if (abs < 60) return `${abs}m`;
  if (abs < 60 * 48) return `${Math.floor(abs / 60)}h ${abs % 60}m`;

  return `${Math.floor(abs / 1440)}d`;
}

const minutesBetween = (fromIso: string, to: number) => Math.round((to - new Date(fromIso).getTime()) / 60_000);

/**
 * ADR-126 decision 23: the Joki orders board, styled on /admin/orders (the
 * benchmark): summary cards, underline filter tabs, the shared DataTable,
 * dot status text, View per row. JokiBoard's filters are the same ones the
 * Dashboard card counts. A waiting order gets Assign, in a dialog.
 */
export function JokiOrdersTab({ initialFilter, reloadKey }: { initialFilter: JokiFilter | "all"; reloadKey: number }) {
  const router = useRouter();
  const [filter, setFilter] = useState<JokiFilter | "all">(initialFilter);
  const [pageNumber, setPageNumber] = useState(1);
  const [page, setPage] = useState<JokiBoardPage | null>(null);
  const [counts, setCounts] = useState<(JokiCounts & { all: number }) | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [assigning, setAssigning] = useState<JokiBoardRow | null>(null);
  const [localReload, setLocalReload] = useState(0);
  // Waiting/SLA are read against the moment the page loaded, not each render.
  const [now, setNow] = useState(0);
  // The /orders filter row: search, plus a penjoki and the same date presets.
  const [search, setSearch] = useState("");
  const debouncedSearch = useDebouncedValue(search);
  const [supplierId, setSupplierId] = useState("all");
  const [penjoki, setPenjoki] = useState<Penjoki[]>([]);
  const [rangePreset, setRangePreset] = useState<DateRangePreset>("all");
  const [customFrom, setCustomFrom] = useState("");
  const [customTo, setCustomTo] = useState("");
  const { from: dateFrom, to: dateTo } = resolveDateRange(rangePreset, customFrom, customTo);
  const filters = useMemo(
    () => ({ search: debouncedSearch || undefined, supplierId: supplierId === "all" ? undefined : Number(supplierId), dateFrom, dateTo }),
    [debouncedSearch, supplierId, dateFrom, dateTo],
  );
  // Reset to page 1 when a filter changes (React's adjust-state-during-render pattern, as /orders does).
  const [filterKey, setFilterKey] = useState(filters);
  if (filterKey !== filters) {
    setFilterKey(filters);
    setPageNumber(1);
  }

  useEffect(() => {
    listPenjoki().then(setPenjoki).catch(() => undefined);
  }, []);

  useEffect(() => {
    let current = true;
    Promise.all([listJokiOrders({ filter, page: pageNumber, ...filters }), getJokiCounts()])
      .then(([orders, summary]) => {
        if (!current) return;
        setPage(orders);
        setCounts(summary);
        setNow(Date.now());
        setError(null);
      })
      .catch((err: unknown) => current && setError(err instanceof ApiError ? err.message : "Could not load joki orders."));

    return () => {
      current = false;
    };
  }, [filter, pageNumber, filters, reloadKey, localReload]);

  function select(next: JokiFilter | "all") {
    setFilter(next);
    setPageNumber(1);
  }

  return (
    <div>
      {error && <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>}

      <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        {CARDS.map((card) => {
          const active = filter === card.value;
          const isWaiting = card.value === "waiting";
          const Icon = card.icon;
          return (
            <button
              key={card.value}
              type="button"
              onClick={() => select(card.value)}
              className={`relative rounded-2xl border p-4 text-left transition-colors ${
                isWaiting
                  ? "border-purple-200 bg-purple-50"
                  : active
                    ? "border-cyan-600 bg-cyan-50 dark:border-cyan-600"
                    : "border-gray-200 bg-surface hover:border-gray-300 dark:border-gray-800 dark:hover:border-gray-700"
              }`}
            >
              {isWaiting && <span aria-hidden="true" className="absolute right-4 top-4 h-2 w-2 rounded-full bg-purple-600" />}
              {Icon && <Icon className="absolute right-4 top-4 h-3.5 w-3.5 text-ink-muted" />}
              <p className={`text-overline font-medium uppercase tracking-wide ${isWaiting ? "text-purple-ink" : "text-ink-muted"}`}>
                {card.value === "all" ? "All Joki" : JOKI_FILTER_LABELS[card.value]}
              </p>
              <p className={`mt-1 text-metric-lg font-semibold ${isWaiting ? "text-purple-ink" : "text-ink"}`}>
                {counts ? counts[card.key] : "—"}
              </p>
            </button>
          );
        })}
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <input
          type="text"
          placeholder="Search order # or email…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="h-11 w-full max-w-sm rounded-lg border border-gray-300 px-4 py-2.5 text-sm shadow-theme-xs focus:border-cyan-600 focus:outline-hidden focus:ring-3 focus:ring-focus-ring/10 dark:border-gray-700 dark:bg-gray-900 dark:text-ink"
        />
        <div className="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800">
          {FILTERS.map((f) => (
            <button
              key={f}
              type="button"
              onClick={() => select(f)}
              className={`shrink-0 border-b-2 px-3 py-1.5 text-sm font-medium ${
                filter === f ? "border-cyan-600 text-cyan-ink" : "border-transparent text-ink-muted hover:text-ink"
              }`}
            >
              {f === "all" ? "All" : JOKI_FILTER_LABELS[f]}
            </button>
          ))}
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <OptionSelect
          ariaLabel="Filter by penjoki"
          className="min-w-[10rem]"
          value={supplierId}
          options={[{ label: "All penjoki", value: "all" }, ...penjoki.map((p) => ({ label: p.name, value: String(p.id) }))]}
          onChange={setSupplierId}
        />
        <OptionSelect ariaLabel="Date range" className="min-w-[9rem]" value={rangePreset} options={DATE_RANGE_PRESETS} onChange={(v) => setRangePreset(v as DateRangePreset)} />
        {rangePreset === "custom" && (
          <>
            <Input type="date" value={customFrom} onChange={(e) => setCustomFrom(e.target.value)} className="w-[9.5rem]" />
            <span className="text-theme-xs text-ink-subtle">to</span>
            <Input type="date" value={customTo} onChange={(e) => setCustomTo(e.target.value)} className="w-[9.5rem]" />
          </>
        )}
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={page?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className={TH}>Order #</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Climb</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Penjoki</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Waiting</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Amount</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Delivery</DataTableTHeadCell>
                    <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const row = item as unknown as JokiBoardRow;
                    const pending = row.delivery_status === "pending";
                    const slaMinutes = row.sla_due_at ? -minutesBetween(row.sla_due_at, now) : null;

                    return (
                      <DataTableRow key={row.id}>
                        <DataTableCell className="whitespace-nowrap px-5 py-4 font-mono text-code-id font-medium text-ink">{row.order_number}</DataTableCell>
                        <DataTableCell className={`${TD} min-w-[13rem]`}>
                          <span className="font-medium text-ink">{row.from_rank ?? "—"} → {row.to_rank ?? "—"}</span>
                          <div className="text-theme-xs text-ink-muted">
                            {[row.current_rank ? `Now ${row.current_rank}` : null, row.role, row.hero_request].filter(Boolean).join(" · ") || "Not started"}
                          </div>
                        </DataTableCell>
                        <DataTableCell className={`${TD} whitespace-nowrap`}>
                          {row.penjoki ? <span className="text-ink">{row.penjoki}</span> : pending ? <span className="text-purple-ink">Unassigned</span> : "—"}
                        </DataTableCell>
                        <DataTableCell className={`${TD} whitespace-nowrap`}>
                          {/* A finished order is no longer waiting: its age since payment would read as e.g. "115d". */}
                          {row.paid_at && (pending || row.delivery_status === "needs_review")
                            ? duration(Math.max(0, minutesBetween(row.paid_at, now)))
                            : "—"}
                          {slaMinutes !== null && (
                            <div className={`text-theme-xs ${slaMinutes < 0 ? "text-danger-ink" : "text-ink-muted"}`}>
                              SLA {duration(slaMinutes)} {slaMinutes < 0 ? "overdue" : "left"}
                            </div>
                          )}
                        </DataTableCell>
                        <DataTableCell className="whitespace-nowrap px-5 py-4 text-theme-sm">
                          <span className="font-medium text-ink">{rm(row.selling_price)}</span>
                          <div className="text-theme-xs text-ink-muted">Payout {rm(row.payout_sen)}</div>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex flex-wrap items-center gap-1.5">
                            <StatusText severity={deliveryStatusSeverity[row.delivery_status as OrderListItem["delivery_status"]] ?? "secondary"}>{row.delivery_status}</StatusText>
                            {row.is_stale && <Tag severity="danger">Stale</Tag>}
                            {row.is_express && <Tag severity="warn">Express</Tag>}
                          </div>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <div className="flex gap-2">
                            {pending && !row.penjoki && (
                              <Button size="small" onClick={() => setAssigning(row)}>Assign</Button>
                            )}
                            <Button size="small" variant="outlined" onClick={() => router.push(`/admin/orders?order=${row.id}`)}>View</Button>
                          </div>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {page?.data.length === 0 && <p className="p-6 text-center text-sm text-ink-muted">No joki orders here.</p>}
          {page === null && !error && <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>}
        </div>
      </div>

      {page && page.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-ink-muted">
          <span>Page {page.current_page} of {page.last_page} ({page.total} total)</span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={page.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>Previous</Button>
            <Button size="small" variant="outlined" disabled={page.current_page >= page.last_page} onClick={() => setPageNumber((p) => p + 1)}>Next</Button>
          </div>
        </div>
      )}

      <Dialog open={assigning !== null} onOpenChange={(e) => { if (!e.value) setAssigning(null); }}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup className="w-full max-w-lg">
              <DialogHeader>
                <DialogTitle>Assign {assigning?.order_number}</DialogTitle>
                <DialogHeaderActions>
                  <DialogClose aria-label="Close">
                    <CloseIcon className="h-5 w-5" />
                  </DialogClose>
                </DialogHeaderActions>
              </DialogHeader>
              <DialogContent>
                {assigning && (
                  <>
                    <p className="mb-5 text-sm text-ink-muted">
                      {assigning.from_rank} → {assigning.to_rank}
                      {assigning.role ? ` · ${assigning.role}` : ""}. The penjoki gets the order and a fresh login link in their WhatsApp group.
                    </p>
                    <JokiAssignControl
                      orderId={assigning.id}
                      currentSupplierId={null}
                      onDone={() => {
                        setAssigning(null);
                        setLocalReload((k) => k + 1);
                      }}
                    />
                  </>
                )}
              </DialogContent>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </div>
  );
}
