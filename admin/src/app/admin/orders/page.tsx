"use client";

/**
 * ORD-1..7 — list with ORD-2's status filters + search (ORD-1), a
 * detail view (ORD-6: customer info, game/package, payment info,
 * supplier response), and retry-delivery (ORD-7 / ADR-014). Voucher
 * issuance (the other ORD-7 action) and export (ORD-5) remain a later
 * pass — see docs/prd.md §14.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Badge from "@/components/ui/badge/Badge";
import Button from "@/components/ui/button/Button";
import { getClientSession } from "@/lib/session";
import type { SessionPayload } from "@/lib/auth";
import { ApiError } from "@/lib/api-client";
import { type OrderListItem, type OrderDetail, type OrderPage, type OrderStatusFilter, listOrders, getOrder, retryOrderDelivery } from "@/lib/orders";

const STATUS_FILTERS: { value: OrderStatusFilter; label: string }[] = [
  { value: "all", label: "All" },
  { value: "need_action", label: "Need Action" },
  { value: "processing", label: "Processing" },
  { value: "completed", label: "Completed" },
  { value: "today", label: "Today" },
];

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

const paymentStatusColor: Record<OrderListItem["payment_status"], "warning" | "success" | "error"> = {
  pending: "warning",
  paid: "success",
  failed: "error",
};

const deliveryStatusColor: Record<OrderListItem["delivery_status"], "light" | "warning" | "success" | "error"> = {
  not_started: "light",
  processing: "warning",
  delivered: "success",
  failed: "error",
};

export default function OrdersPage() {
  const router = useRouter();
  // Read in an effect, not render body — see UserDropdown.tsx for why.
  const [session, setSession] = useState<SessionPayload | null>(null);

  const [status, setStatus] = useState<OrderStatusFilter>("all");
  const [search, setSearch] = useState("");
  const [pageNumber, setPageNumber] = useState(1);
  const [page, setPage] = useState<OrderPage | null>(null);
  const [error, setError] = useState<string | null>(null);

  const [selected, setSelected] = useState<OrderDetail | null>(null);
  const [retrying, setRetrying] = useState(false);
  const [retryMessage, setRetryMessage] = useState<string | null>(null);

  async function openOrder(token: string, id: number) {
    setSelected(null);
    setRetryMessage(null);
    try {
      setSelected(await getOrder(token, id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this order.");
    }
  }

  async function handleRetryDelivery() {
    if (!session || !selected) return;
    setRetrying(true);
    setRetryMessage(null);
    try {
      await retryOrderDelivery(session.token, selected.id);
      setRetryMessage("Retry queued — refresh in a moment to see the outcome.");
    } catch (err) {
      setRetryMessage(err instanceof ApiError ? err.message : "Could not queue a retry.");
    } finally {
      setRetrying(false);
    }
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    setSession(s);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    setPageNumber(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, search]);

  useEffect(() => {
    if (!session) return;

    listOrders(session.token, { status, search: search || undefined, page: pageNumber })
      .then(setPage)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load orders.");
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, status, search, pageNumber]);

  if (selected) {
    return (
      <div>
        <button
          onClick={() => setSelected(null)}
          className="mb-4 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white"
        >
          ← Back to orders
        </button>

        <div className="mb-6">
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">{selected.order_number}</h1>
          <p className="mt-1 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
            <Badge size="sm" color={paymentStatusColor[selected.payment_status]}>
              payment: {selected.payment_status}
            </Badge>
            <Badge size="sm" color={deliveryStatusColor[selected.delivery_status]}>
              delivery: {selected.delivery_status}
            </Badge>
          </p>
          {/* ORD-7 / ADR-014: only a failed delivery can be retried — mirrors the backend guard exactly. */}
          {selected.delivery_status === "failed" && (
            <div className="mt-3 flex items-center gap-3">
              <Button size="sm" onClick={handleRetryDelivery} disabled={retrying}>
                {retrying ? "Queuing retry…" : "Retry Delivery"}
              </Button>
              {retryMessage && <span className="text-sm text-gray-500 dark:text-gray-400">{retryMessage}</span>}
            </div>
          )}
        </div>

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Customer</h2>
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Email</dt><dd>{selected.customer_email}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Phone</dt><dd>{selected.customer_phone ?? "—"}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Player ID</dt><dd>{selected.player_id}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Server/Zone ID</dt><dd>{selected.server_id ?? "—"}</dd></div>
            </dl>
          </div>

          <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Game / Package</h2>
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Game</dt><dd>{selected.game?.name ?? "—"}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Package</dt><dd>{selected.package?.name ?? "—"}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Supplier</dt><dd>{selected.supplier?.name ?? "—"}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Reseller</dt><dd>{selected.reseller?.business_name ?? "—"}</dd></div>
            </dl>
          </div>

          <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Pricing (ORD-9, server-computed)</h2>
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Cost Price</dt><dd>{formatRm(selected.cost_price)}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Reseller Cost Price</dt><dd>{formatRm(selected.reseller_cost_price)}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Selling Price</dt><dd>{formatRm(selected.selling_price)}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Voucher Discount</dt><dd>{selected.voucher_discount ? formatRm(selected.voucher_discount) : "—"}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Transaction Fee</dt><dd>{formatRm(selected.transaction_fee)}</dd></div>
              <div className="flex justify-between font-medium text-gray-800 dark:text-white/90"><dt>Final Amount</dt><dd>{formatRm(selected.final_amount)}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Platform Profit</dt><dd>{formatRm(selected.platform_profit)}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Reseller Profit</dt><dd>{formatRm(selected.reseller_profit)}</dd></div>
            </dl>
          </div>

          <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Payment / Supplier</h2>
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Payment Method</dt><dd>{selected.payment_method ?? "—"}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Payment Ref (Xendit)</dt><dd>{selected.payment_ref ?? "—"}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Reference # (ORD-8)</dt><dd>{selected.reference_number ?? "—"}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Supplier Ref</dt><dd>{selected.supplier_ref ?? "—"}</dd></div>
            </dl>
            {selected.supplier_response && (
              <>
                <p className="mt-4 mb-1 text-theme-xs text-gray-400">Supplier response</p>
                <pre className="overflow-x-auto rounded-lg bg-gray-100 p-3 text-theme-xs text-gray-600 dark:bg-white/5 dark:text-gray-400">
                  {JSON.stringify(selected.supplier_response, null, 2)}
                </pre>
              </>
            )}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Orders</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Every order created via checkout — real customer purchases only.
        </p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <input
          type="text"
          placeholder="Search order # or customer email…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="h-11 w-full max-w-sm rounded-lg border border-gray-300 px-4 py-2.5 text-sm shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
        />
        <div className="flex gap-2">
          {STATUS_FILTERS.map((f) => (
            <button
              key={f.value}
              onClick={() => setStatus(f.value)}
              className={`rounded-lg px-3 py-1.5 text-sm ${status === f.value ? "bg-brand-500 text-white" : "bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400"}`}
            >
              {f.label}
            </button>
          ))}
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Order #</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Customer</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Game / Package</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Final Amount</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Payment</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Delivery</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {page?.data.map((order) => (
                <TableRow key={order.id}>
                  <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{order.order_number}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{order.customer_email}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                    {order.game?.name ?? "—"}
                    {order.package?.name && <span className="text-theme-xs text-gray-400"> · {order.package.name}</span>}
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{formatRm(order.final_amount)}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <Badge size="sm" color={paymentStatusColor[order.payment_status]}>{order.payment_status}</Badge>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <Badge size="sm" color={deliveryStatusColor[order.delivery_status]}>{order.delivery_status}</Badge>
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                    {new Date(order.created_at).toLocaleString()}
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm">
                    <Button size="sm" variant="outline" onClick={() => session && openOrder(session.token, order.id)}>
                      View
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          {page?.data.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No orders found.</p>
          )}
          {page === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>

      {page && page.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
          <span>Page {page.current_page} of {page.last_page} ({page.total} total)</span>
          <div className="flex gap-2">
            <Button size="sm" variant="outline" disabled={page.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button size="sm" variant="outline" disabled={page.current_page >= page.last_page} onClick={() => setPageNumber((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
