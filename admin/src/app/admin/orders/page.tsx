"use client";

/**
 * ORD-1..7 — list with ORD-2's status filters + search (ORD-1), a
 * detail view (ORD-6: customer info, game/package, payment info,
 * supplier response), and a single "Resend Delivery" action (ADR-017)
 * that folds ORD-7's original plain retry into the same flow — the
 * package picker defaults to the order's own package (a same-package
 * resend behaves like the old "Retry Delivery" button did), with the
 * option to swap to a different same-game package. The plain
 * `POST /api/orders/{order}/retry-delivery` endpoint (ADR-014) still
 * exists on the backend, just no longer has its own separate button
 * here — two buttons for "try to fix a failed delivery" was more
 * confusing than useful (founder feedback, docs/prd.md §14). Voucher
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
import { type OrderListItem, type OrderDetail, type OrderPage, type OrderStatusFilter, listOrders, getOrder } from "@/lib/orders";
import ResendDeliveryModal from "@/components/orders/ResendDeliveryModal";

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
  const [resendModalOpen, setResendModalOpen] = useState(false);
  const [resendMessage, setResendMessage] = useState<string | null>(null);

  async function openOrder(token: string, id: number) {
    setSelected(null);
    setResendMessage(null);
    try {
      setSelected(await getOrder(token, id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this order.");
    }
  }

  function handleResent() {
    setResendMessage("Resend queued — refresh in a moment to see the outcome and the new Delivery Logs entry.");
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
      <>
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
          {/* ADR-017: one action for "fix a failed delivery" — defaults to resending the same package (the old plain "Retry Delivery" behavior), with the option to swap packages inside the modal. Only a failed delivery can be resent — mirrors the backend guard exactly. */}
          {selected.delivery_status === "failed" && (
            <div className="mt-3 flex flex-wrap items-center gap-3">
              <Button size="sm" onClick={() => setResendModalOpen(true)}>
                Resend Delivery…
              </Button>
              {resendMessage && <span className="text-sm text-gray-500 dark:text-gray-400">{resendMessage}</span>}
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

        {/* ADR-017 decision #4: every resend attempt, not just the latest supplier_response. */}
        {selected.resend_attempts.length > 0 && (
          <div className="mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <h2 className="p-6 pb-0 text-sm font-semibold text-gray-800 dark:text-white/90">Delivery Logs</h2>
            <div className="max-w-full overflow-x-auto p-6 pt-4">
              <Table>
                <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                  <TableRow>
                    <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</TableCell>
                    <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Package</TableCell>
                    <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Price Diff</TableCell>
                    <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Outcome</TableCell>
                    <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Triggered By</TableCell>
                    <TableCell isHeader className="px-3 py-2 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Note</TableCell>
                  </TableRow>
                </TableHeader>
                <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {selected.resend_attempts.map((attempt) => (
                    <TableRow key={attempt.id}>
                      <TableCell className="px-3 py-3 text-theme-xs text-gray-500 dark:text-gray-400">
                        {new Date(attempt.created_at).toLocaleString()}
                      </TableCell>
                      <TableCell className="px-3 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                        {attempt.package?.name ?? "—"}
                      </TableCell>
                      <TableCell className="px-3 py-3 text-theme-sm">
                        <span className={attempt.price_diff_sen > 0 ? "text-error-600 dark:text-error-400" : attempt.price_diff_sen < 0 ? "text-success-600 dark:text-success-400" : "text-gray-500 dark:text-gray-400"}>
                          {attempt.price_diff_sen > 0 ? "+" : ""}
                          {formatRm(attempt.price_diff_sen)}
                        </span>
                      </TableCell>
                      <TableCell className="px-3 py-3 text-theme-sm">
                        <Badge size="sm" color={attempt.outcome === "success" ? "success" : "error"}>{attempt.outcome}</Badge>
                      </TableCell>
                      <TableCell className="px-3 py-3 text-theme-xs text-gray-500 dark:text-gray-400">{attempt.triggered_by ?? "—"}</TableCell>
                      <TableCell className="px-3 py-3 text-theme-xs text-gray-500 dark:text-gray-400">{attempt.note ?? "—"}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </div>
        )}
      </div>

      {session && (
        <ResendDeliveryModal
          isOpen={resendModalOpen}
          onClose={() => setResendModalOpen(false)}
          onResent={handleResent}
          order={selected}
          token={session.token}
        />
      )}
    </>
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
