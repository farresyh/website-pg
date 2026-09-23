"use client";

import { useEffect, useState, type ChangeEvent } from "react";
import Link from "next/link";
import { Button } from "primereact/button";
import { InputText } from "primereact/inputtext";
import { Select } from "primereact/select";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  listOrders,
  type OrderFilters,
  type OrderListItem,
  type Paginated,
} from "@/lib/portal";
import {
  formatRm,
  formatDateTime,
  humanize,
  paymentSeverity,
  deliverySeverity,
} from "@/lib/format";
import { PageHeader, Panel, StatusTag, ErrorNote, EmptyRow } from "@/components/ui";

const PAYMENT_OPTIONS = ["", "paid", "pending", "failed"];
const DELIVERY_OPTIONS = [
  "",
  "not_started",
  "processing",
  "pending",
  "needs_review",
  "delivered",
  "failed",
];

export default function OrdersPage() {
  const session = useClientSession();
  const ownerType = session?.owner_type ?? "affiliate";
  const [page, setPage] = useState(1);
  const [paymentStatus, setPaymentStatus] = useState("");
  const [deliveryStatus, setDeliveryStatus] = useState("");
  const [search, setSearch] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [data, setData] = useState<Paginated<OrderListItem> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;

    const filters: OrderFilters = { page };
    if (paymentStatus) filters.payment_status = paymentStatus as OrderFilters["payment_status"];
    if (deliveryStatus) filters.delivery_status = deliveryStatus as OrderFilters["delivery_status"];
    if (appliedSearch) filters.search = appliedSearch;

    let cancelled = false;
    listOrders(session.token, ownerType, filters)
      .then((result) => {
        if (cancelled) return;
        setData(result);
        setError(null);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : "Could not load orders.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [page, paymentStatus, deliveryStatus, appliedSearch, ownerType]);

  function resetToFirstPage<T>(setter: (v: T) => void) {
    return (value: T) => {
      setLoading(true);
      setPage(1);
      setter(value);
    };
  }

  return (
    <div>
      <PageHeader
        title="Orders"
        subtitle={
          ownerType === "affiliate"
            ? "Every order placed on your storefront."
            : "Your own wallet order history."
        }
      />

      {error && <ErrorNote message={error} />}

      <div className="mb-5 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="flex flex-wrap items-end gap-3">
        <label className="text-theme-xs text-gray-500 dark:text-gray-400">
          Payment
          <Select.Root
            value={paymentStatus || "all"}
            options={PAYMENT_OPTIONS.map((o) => ({ value: o || "all", label: o ? humanize(o) : "All" }))}
            optionLabel="label"
            optionValue="value"
            onValueChange={(e: { value: unknown }) => resetToFirstPage(setPaymentStatus)(e.value === "all" ? "" : e.value as string)}
            className="mt-1 block min-h-11 w-full sm:w-40"
          >
            <Select.Trigger className="flex min-h-11 w-full items-center justify-between rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-theme-sm text-gray-800 focus-visible:outline-2 focus-visible:outline-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
              <Select.Value />
              <Select.Indicator className="shrink-0 text-gray-400 transition-transform data-open:rotate-180" />
            </Select.Trigger>
            <Select.Portal>
              <Select.Positioner className="z-[100000]">
                <Select.Popup className="z-[100000] max-h-60 overflow-auto rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg dark:border-gray-800 dark:bg-gray-900">
                  <Select.List className="text-sm">
                    {PAYMENT_OPTIONS.map((o, index) => <Select.Option key={o} index={index} value={o || "all"} className="mx-1 cursor-pointer rounded-md px-3 py-2 text-gray-700 data-focused:bg-gray-100 data-selected:font-medium data-selected:text-brand-600 dark:text-gray-300 dark:data-focused:bg-white/[0.05] dark:data-selected:text-brand-400">{o ? humanize(o) : "All"}</Select.Option>)}
                  </Select.List>
                </Select.Popup>
              </Select.Positioner>
            </Select.Portal>
          </Select.Root>
        </label>
        <label className="text-theme-xs text-gray-500 dark:text-gray-400">
          Delivery
          <Select.Root
            value={deliveryStatus || "all"}
            options={DELIVERY_OPTIONS.map((o) => ({ value: o || "all", label: o ? humanize(o) : "All" }))}
            optionLabel="label"
            optionValue="value"
            onValueChange={(e: { value: unknown }) => resetToFirstPage(setDeliveryStatus)(e.value === "all" ? "" : e.value as string)}
            className="mt-1 block min-h-11 w-full sm:w-48"
          >
            <Select.Trigger className="flex min-h-11 w-full items-center justify-between rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-theme-sm text-gray-800 focus-visible:outline-2 focus-visible:outline-brand-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
              <Select.Value />
              <Select.Indicator className="shrink-0 text-gray-400 transition-transform data-open:rotate-180" />
            </Select.Trigger>
            <Select.Portal>
              <Select.Positioner className="z-[100000]">
                <Select.Popup className="z-[100000] max-h-60 overflow-auto rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg dark:border-gray-800 dark:bg-gray-900">
                  <Select.List className="text-sm">
                    {DELIVERY_OPTIONS.map((o, index) => <Select.Option key={o} index={index} value={o || "all"} className="mx-1 cursor-pointer rounded-md px-3 py-2 text-gray-700 data-focused:bg-gray-100 data-selected:font-medium data-selected:text-brand-600 dark:text-gray-300 dark:data-focused:bg-white/[0.05] dark:data-selected:text-brand-400">{o ? humanize(o) : "All"}</Select.Option>)}
                  </Select.List>
                </Select.Popup>
              </Select.Positioner>
            </Select.Portal>
          </Select.Root>
        </label>
        {ownerType === "affiliate" && (
          <form
            className="flex items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              setLoading(true);
              setPage(1);
              setAppliedSearch(search.trim());
            }}
          >
            <label className="text-theme-xs text-gray-500 dark:text-gray-400">
              Search
              <InputText
                value={search}
                onChange={(e: ChangeEvent<HTMLInputElement>) => setSearch(e.target.value)}
                placeholder="Order no. / ref / email"
                className="mt-1 block min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-theme-sm text-gray-800 focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 sm:w-56 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
              />
            </label>
            <Button type="submit" severity="secondary" variant="outlined" className="min-h-11 rounded-lg border border-gray-200 px-4 text-sm font-medium text-gray-700 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Apply</Button>
          </form>
        )}
        </div>
      </div>

      <Panel>
        <div className="md:hidden">
          {loading && <EmptyRow>Loading…</EmptyRow>}
          {!loading && data?.data.length === 0 && <EmptyRow>No orders match these filters.</EmptyRow>}
          {!loading && data?.data && data.data.length > 0 && (
            <div className="divide-y divide-gray-100 dark:divide-gray-800">
              {data.data.map((order) => <MobileOrderCard key={order.order_number} order={order} ownerType={ownerType} />)}
            </div>
          )}
        </div>
        <div className="hidden max-w-full overflow-x-auto md:block">
          <table className="min-w-full text-theme-sm">
            <thead className="border-b border-gray-100 dark:border-gray-800">
              <tr className="text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                <th className="px-5 py-3">Order</th>
                <th className="px-5 py-3">Game / Package</th>
                <th className="px-5 py-3">Total</th>
                {ownerType === "affiliate" && <th className="px-5 py-3">Your margin</th>}
                <th className="px-5 py-3">Payment</th>
                <th className="px-5 py-3">Delivery</th>
                <th className="px-5 py-3">Date</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {data?.data.map((order) => (
                <tr key={order.order_number} className="text-gray-600 dark:text-gray-300">
                  <td className="px-5 py-4">
                    <Link
                      href={`/orders/${order.order_number}`}
                      className="font-medium text-brand-500 hover:underline dark:text-brand-400"
                    >
                      {order.order_number}
                    </Link>
                  </td>
                  <td className="px-5 py-4">
                    {order.game?.name ?? "—"}
                    <span className="block text-theme-xs text-gray-400">
                      {order.package_name ?? "—"}
                    </span>
                  </td>
                  <td className="px-5 py-4 font-medium text-gray-800 dark:text-white/90">
                    {formatRm(order.final_amount)}
                  </td>
                  {ownerType === "affiliate" && (
                    <td className="px-5 py-4">{formatRm(order.affiliate_profit ?? 0)}</td>
                  )}
                  <td className="px-5 py-4">
                    <StatusTag severity={paymentSeverity(order.payment_status)}>
                      {order.payment_status}
                    </StatusTag>
                  </td>
                  <td className="px-5 py-4">
                    <div className="flex flex-wrap gap-1">
                      <StatusTag severity={deliverySeverity(order.delivery_status)}>
                        {humanize(order.delivery_status)}
                      </StatusTag>
                      {ownerType === "reseller" && order.wallet_refunded && (
                        <StatusTag severity="info">Wallet Refunded</StatusTag>
                      )}
                      {ownerType === "affiliate" && order.has_compensation_voucher && (
                        <StatusTag severity="info">Voucher Issued</StatusTag>
                      )}
                      {ownerType === "affiliate" && order.has_voucher_restored && (
                        <StatusTag severity="info">Voucher Restored</StatusTag>
                      )}
                    </div>
                  </td>
                  <td className="px-5 py-4 text-theme-xs text-gray-400">
                    {formatDateTime(order.paid_at ?? order.created_at)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {loading && <EmptyRow>Loading…</EmptyRow>}
          {!loading && data?.data.length === 0 && <EmptyRow>No orders match these filters.</EmptyRow>}
        </div>
      </Panel>

      {data && data.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-theme-sm text-gray-500 dark:text-gray-400">
          <span>
            Page {data.current_page} of {data.last_page} · {data.total} orders
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={data.current_page <= 1}
              onClick={() => {
                setLoading(true);
                setPage((p) => Math.max(1, p - 1));
              }}
              className="min-h-11 rounded-lg border border-gray-200 px-4 text-theme-xs disabled:opacity-40 dark:border-gray-700"
            >
              Previous
            </button>
            <button
              type="button"
              disabled={data.current_page >= data.last_page}
              onClick={() => {
                setLoading(true);
                setPage((p) => p + 1);
              }}
              className="min-h-11 rounded-lg border border-gray-200 px-4 text-theme-xs disabled:opacity-40 dark:border-gray-700"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function MobileOrderCard({
  order,
  ownerType,
}: {
  order: OrderListItem;
  ownerType: "affiliate" | "reseller";
}) {
  return (
    <Link
      href={`/orders/${order.order_number}`}
      className="block p-4 transition-colors hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-brand-500 dark:hover:bg-white/5"
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate font-medium text-gray-800 dark:text-white/90">{order.order_number}</p>
          <p className="mt-1 truncate text-sm text-gray-600 dark:text-gray-300">{order.game?.name ?? "—"}</p>
          <p className="truncate text-theme-xs text-gray-400">{order.package_name ?? "—"}</p>
        </div>
        <p className="shrink-0 font-semibold text-gray-800 dark:text-white/90">{formatRm(order.final_amount)}</p>
      </div>
      <div className="mt-4 grid grid-cols-2 gap-3 text-theme-xs">
        <OrderFact label="Payment"><StatusTag severity={paymentSeverity(order.payment_status)}>{order.payment_status}</StatusTag></OrderFact>
        <OrderFact label="Delivery"><StatusTag severity={deliverySeverity(order.delivery_status)}>{humanize(order.delivery_status)}</StatusTag></OrderFact>
        {ownerType === "affiliate" && <OrderFact label="Your margin">{formatRm(order.affiliate_profit ?? 0)}</OrderFact>}
        <OrderFact label="Date">{formatDateTime(order.paid_at ?? order.created_at)}</OrderFact>
      </div>
      {(order.wallet_refunded || order.has_compensation_voucher || order.has_voucher_restored) && (
        <div className="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
          <p className="mb-1 text-theme-xs font-medium uppercase tracking-wide text-gray-400">Compensation</p>
          <div className="flex flex-wrap gap-1">
            {order.wallet_refunded && <StatusTag severity="info">Wallet Refunded</StatusTag>}
            {order.has_compensation_voucher && <StatusTag severity="info">Voucher Issued</StatusTag>}
            {order.has_voucher_restored && <StatusTag severity="info">Voucher Restored</StatusTag>}
          </div>
        </div>
      )}
    </Link>
  );
}

function OrderFact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="min-w-0">
      <p className="mb-1 font-medium uppercase tracking-wide text-gray-400">{label}</p>
      <div className="truncate text-gray-700 dark:text-gray-200">{children}</div>
    </div>
  );
}
