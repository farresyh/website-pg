"use client";

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  countOrdersByDelivery,
  listOrders,
  type DeliveryStatus,
  type OrderFilters,
  type OrderListItem,
  type Paginated,
  type PaymentStatus,
} from "@/lib/portal";
import { ErrorNote, PageHeader, Pager, Panel, Skeleton } from "@/components/composites";
import { Button } from "@/components/ui/button";
import { PortalIcon } from "@/components/shell/PortalIcon";
import OrderTable from "@/components/orders/OrderTable";

const PER_PAGE = 20;

/** The delivery tabs: one server filter each. Not started and partly delivered stay under All. */
const TABS: { status: DeliveryStatus | null; label: string }[] = [
  { status: null, label: "All" },
  { status: "processing", label: "Processing" },
  { status: "needs_review", label: "Needs review" },
  { status: "delivered", label: "Delivered" },
  { status: "failed", label: "Failed" },
];

const PAYMENTS: { value: PaymentStatus | ""; label: string }[] = [
  { value: "", label: "Any payment" },
  { value: "paid", label: "Paid" },
  { value: "pending", label: "Pending" },
  { value: "failed", label: "Failed" },
];

export default function OrdersPage() {
  const session = useClientSession();
  const ownerType = session?.owner_type ?? "affiliate";
  const [page, setPage] = useState(1);
  const [delivery, setDelivery] = useState<DeliveryStatus | null>(null);
  const [payment, setPayment] = useState<PaymentStatus | "">("");
  const [search, setSearch] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [data, setData] = useState<Paginated<OrderListItem> | null>(null);
  const [counts, setCounts] = useState<Record<string, number> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    const current = getClientSession();
    if (!current) return;
    let cancelled = false;
    countOrdersByDelivery(current.token, current.owner_type, TABS.flatMap((t) => (t.status ? [t.status] : [])))
      .then((result) => !cancelled && setCounts(result))
      .catch(() => !cancelled && setCounts(null));
    return () => {
      cancelled = true;
    };
  }, [refreshKey]);

  useEffect(() => {
    const current = getClientSession();
    if (!current) return;
    const filters: OrderFilters = { page, per_page: PER_PAGE };
    if (delivery) filters.delivery_status = delivery;
    if (payment) filters.payment_status = payment;
    if (appliedSearch) filters.search = appliedSearch;

    let cancelled = false;
    listOrders(current.token, current.owner_type, filters)
      .then((result) => {
        if (cancelled) return;
        setData(result);
        setError(null);
      })
      .catch((err: unknown) => !cancelled && setError(err instanceof ApiError ? err.message : "Could not load orders."))
      .finally(() => !cancelled && setLoading(false));
    return () => {
      cancelled = true;
    };
  }, [page, delivery, payment, appliedSearch, refreshKey]);

  function update(apply: () => void) {
    setLoading(true);
    setPage(1);
    apply();
  }

  return (
    <div>
      <PageHeader
        title="Orders"
        subtitle={
          ownerType === "affiliate"
            ? "Every order placed on your storefront. Payment and delivery are shown separately."
            : "Every order placed through your API keys and WhatsApp bot. Payment and delivery are shown separately."
        }
        action={
          <Button
            type="button"
            onClick={() => {
              setLoading(true);
              setRefreshKey((k) => k + 1);
            }}
            variant="outlined"
            severity="secondary"
          >
            <PortalIcon name="refresh" className="h-4 w-4" />
            Refresh
          </Button>
        }
      />
      {error && <ErrorNote message={error} />}

      <Panel>
        <div role="tablist" aria-label="Delivery" className="no-scrollbar flex gap-0.5 overflow-x-auto border-b border-border px-4">
          {TABS.map((tab) => {
            const selected = delivery === tab.status;
            const count = counts?.[tab.status ?? "all"];
            return (
              <button
                key={tab.label}
                type="button"
                role="tab"
                aria-selected={selected}
                onClick={() => update(() => setDelivery(tab.status))}
                className={`inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap px-2.5 text-[13px] ${
                  selected ? "font-semibold text-ink shadow-[inset_0_-2px_0_var(--color-cyan-600)]" : "font-medium text-ink-muted hover:text-ink"
                }`}
              >
                {tab.label}
                {count !== undefined && (
                  <span
                    className={`inline-flex h-[18px] min-w-5 items-center justify-center rounded-sm px-1.5 text-[11px] font-semibold ${
                      selected ? "bg-cyan-50 text-cyan-ink" : "bg-subtle text-ink-subtle"
                    } ${count === 0 ? "opacity-60" : ""}`}
                  >
                    {count}
                  </span>
                )}
              </button>
            );
          })}
        </div>

        <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
          <label className="sr-only" htmlFor="order-payment">
            Payment
          </label>
          <select
            id="order-payment"
            value={payment}
            onChange={(e) => update(() => setPayment(e.target.value as PaymentStatus | ""))}
            className={`min-h-11 rounded-md border bg-surface px-3 text-[13px] font-medium sm:min-h-9 ${
              payment ? "border-cyan-600 bg-cyan-50 text-cyan-ink" : "border-border-control text-ink"
            }`}
          >
            {PAYMENTS.map((p) => (
              <option key={p.value} value={p.value}>
                {p.label}
              </option>
            ))}
          </select>
          {ownerType === "affiliate" && (
            <form
              role="search"
              className="flex min-w-0 flex-1 items-center gap-2 sm:max-w-sm"
              onSubmit={(e) => {
                e.preventDefault();
                update(() => setAppliedSearch(search.trim()));
              }}
            >
              <span className="relative min-w-0 flex-1">
                <PortalIcon name="search" className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-ink-subtle" />
                <input
                  type="search"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Order #, reference or email"
                  aria-label="Search orders"
                  className="min-h-11 w-full rounded-md border border-border-control bg-surface pr-3 pl-9 text-[13px] text-ink placeholder:text-ink-subtle sm:min-h-9"
                />
              </span>
              <Button type="submit" variant="outlined" severity="secondary">
                Search
              </Button>
            </form>
          )}
          <span className="ml-auto hidden text-xs text-ink-subtle sm:inline">Malaysia time (GMT+8)</span>
        </div>

        {loading && !data ? (
          <div className="space-y-2 p-5">
            {Array.from({ length: 5 }, (_, i) => (
              <Skeleton key={i} className="h-12" />
            ))}
          </div>
        ) : data && data.data.length > 0 ? (
          <div className={loading ? "opacity-60 transition-opacity" : ""}>
            <OrderTable orders={data.data} ownerType={ownerType} />
          </div>
        ) : (
          <p className="p-8 text-center text-sm text-ink-subtle">{data ? "No orders match these filters." : ""}</p>
        )}

        {data && (
          <Pager
            page={data.current_page}
            lastPage={data.last_page}
            total={data.total}
            perPage={data.per_page}
            onPage={(next) => {
              setLoading(true);
              setPage(next);
            }}
          />
        )}
      </Panel>
    </div>
  );
}
