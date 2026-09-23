"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
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

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <label className="text-theme-xs text-gray-500 dark:text-gray-400">
          Payment
          <select
            value={paymentStatus}
            onChange={(e) => resetToFirstPage(setPaymentStatus)(e.target.value)}
            className="mt-1 block rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-theme-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
          >
            {PAYMENT_OPTIONS.map((o) => (
              <option key={o} value={o}>
                {o ? humanize(o) : "All"}
              </option>
            ))}
          </select>
        </label>
        <label className="text-theme-xs text-gray-500 dark:text-gray-400">
          Delivery
          <select
            value={deliveryStatus}
            onChange={(e) => resetToFirstPage(setDeliveryStatus)(e.target.value)}
            className="mt-1 block rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-theme-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
          >
            {DELIVERY_OPTIONS.map((o) => (
              <option key={o} value={o}>
                {o ? humanize(o) : "All"}
              </option>
            ))}
          </select>
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
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Order no. / ref / email"
                className="mt-1 block w-56 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-theme-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
              />
            </label>
            <button
              type="submit"
              className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
            >
              Apply
            </button>
          </form>
        )}
      </div>

      <Panel>
        <div className="max-w-full overflow-x-auto">
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
          {!loading && data?.data.length === 0 && (
            <EmptyRow>No orders match these filters.</EmptyRow>
          )}
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
              className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs disabled:opacity-40 dark:border-gray-700"
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
              className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs disabled:opacity-40 dark:border-gray-700"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
