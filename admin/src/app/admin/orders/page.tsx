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
 * confusing than useful (founder feedback, docs/prd.md §14). "Issue
 * Voucher" (ORD-7's other resolution path, ADR-004) sits alongside
 * Resend Delivery, hidden once `order.voucher` is already set (at
 * most one per order). ADR-026 (ORD-10): a `needs_review` order gets
 * the same Resend Delivery action plus a new "Mark as Delivered…" —
 * "Issue Voucher" is deliberately never shown for this state (decision
 * 4c), the one exit this feature does not open. Export (ORD-5) remains
 * a later pass.
 */

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
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
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { type OrderListItem, type OrderDetail, type OrderPage, type OrderStatusFilter, listOrders, getOrder, refundOrderToWallet } from "@/lib/orders";
import type { Voucher } from "@/lib/vouchers";
import ResendDeliveryModal from "@/components/orders/ResendDeliveryModal";
import IssueVoucherModal from "@/components/orders/IssueVoucherModal";
import MarkDeliveredModal from "@/components/orders/MarkDeliveredModal";
import NeedsReviewBanner from "@/components/orders/NeedsReviewBanner";
import OrderDetailCards from "@/components/orders/OrderDetailCards";
import DeliveryLogsTable from "@/components/orders/DeliveryLogsTable";

const STATUS_FILTERS: { value: OrderStatusFilter; label: string }[] = [
  { value: "all", label: "All" },
  { value: "need_action", label: "Need Action" },
  { value: "needs_review", label: "Needs Review" },
  { value: "pending_delivery", label: "Pending (Supplier)" },
  { value: "processing", label: "Processing" },
  { value: "completed", label: "Completed" },
  { value: "awaiting_payment", label: "Awaiting Payment" },
  { value: "today", label: "Today" },
];

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

const paymentStatusSeverity: Record<OrderListItem["payment_status"], "warn" | "success" | "danger"> = {
  pending: "warn",
  paid: "success",
  failed: "danger",
};

const deliveryStatusSeverity: Record<OrderListItem["delivery_status"], "secondary" | "warn" | "success" | "danger" | "info"> = {
  not_started: "secondary",
  processing: "warn",
  delivered: "success",
  failed: "danger",
  needs_review: "warn",
  // ADR-032 — a distinct color from "processing" so an admin can tell
  // at a glance this is waiting on an async supplier, not a normal
  // in-flight delivery attempt.
  pending: "info",
};

export default function OrdersPage() {
  return (
    <Suspense fallback={<p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>}>
      <OrdersPageInner />
    </Suspense>
  );
}

/** Wrapped in Suspense above — useSearchParams() (ORD-6 deep-link from Customer Analytics) requires it. */
function OrdersPageInner() {
  const router = useRouter();
  const searchParams = useSearchParams();
  // Read in an effect, not render body — see UserDropdown.tsx for why.
  const session = useClientSession();

  const [status, setStatus] = useState<OrderStatusFilter>("all");
  const [search, setSearch] = useState("");
  const [pageNumber, setPageNumber] = useState(1);
  // Adjusted during render (React's own pattern for "reset state when
  // other state changes"), not in an effect — resets pagination to 1
  // whenever the filter/search changes, without a synchronous setState
  // call inside an effect.
  const [paginationFilterKey, setPaginationFilterKey] = useState({ status, search });
  if (paginationFilterKey.status !== status || paginationFilterKey.search !== search) {
    setPaginationFilterKey({ status, search });
    setPageNumber(1);
  }
  const [page, setPage] = useState<OrderPage | null>(null);
  const [error, setError] = useState<string | null>(null);

  const [selected, setSelected] = useState<OrderDetail | null>(null);
  const [resendModalOpen, setResendModalOpen] = useState(false);
  const [resendMessage, setResendMessage] = useState<string | null>(null);
  const [voucherModalOpen, setVoucherModalOpen] = useState(false);
  const [voucherMessage, setVoucherMessage] = useState<string | null>(null);
  const [markDeliveredModalOpen, setMarkDeliveredModalOpen] = useState(false);
  const [markDeliveredMessage, setMarkDeliveredMessage] = useState<string | null>(null);
  const [refundingToWallet, setRefundingToWallet] = useState(false);
  const [refundMessage, setRefundMessage] = useState<string | null>(null);

  async function openOrder(token: string, id: number) {
    setSelected(null);
    setResendMessage(null);
    setVoucherMessage(null);
    setMarkDeliveredMessage(null);
    setRefundMessage(null);
    try {
      setSelected(await getOrder(token, id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this order.");
    }
  }

  function handleResent() {
    setResendMessage("Resend queued — refresh in a moment to see the outcome and the new Delivery Logs entry.");
  }

  function handleVoucherIssued(voucher: Voucher) {
    setVoucherMessage(`Voucher ${voucher.code} issued.`);
    setSelected((current) => (current ? { ...current, voucher } : current));
  }

  function handleMarkedDelivered(updated: OrderDetail) {
    setMarkDeliveredMessage("Delivery confirmed manually — ledger profit credited.");
    setSelected(updated);
  }

  async function handleRefundToWallet() {
    const session = getClientSession();
    if (!session || !selected) return;
    setRefundingToWallet(true);
    try {
      const updated = await refundOrderToWallet(session.token, selected.id);
      setRefundMessage(`Refunded ${formatRm(updated.final_amount)} to ${updated.wallet_reseller?.business_name}'s wallet.`);
      setSelected(updated);
    } catch (err) {
      setRefundMessage(err instanceof ApiError ? err.message : "Refund failed.");
    } finally {
      setRefundingToWallet(false);
    }
  }

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

    listOrders(session.token, { status, search: search || undefined, page: pageNumber })
      .then(setPage)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load orders.");
      });
     
  }, [session, status, search, pageNumber]);

  // ORD-6 deep-link — Customer Analytics' Order History rows link here
  // as /admin/orders?order={id}. A plain .then()/.catch() chain, not
  // openOrder() (which resets several message states synchronously as
  // its first statements) — calling that directly in an effect body
  // would reintroduce the exact set-state-in-effect pattern this
  // codebase already had a dedicated cleanup pass for.
  const orderIdParam = searchParams.get("order");

  useEffect(() => {
    if (!session || !orderIdParam) return;

    const id = Number(orderIdParam);
    if (!Number.isFinite(id)) return;

    getOrder(session.token, id)
      .then(setSelected)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load this order.");
      });
  }, [session, orderIdParam]);

  if (selected) {
    return (
      <>
      <div>
        <button
          onClick={() => {
            setSelected(null);
            // Drop the deep-link query param too, or navigating "back"
            // would still point at this same order on refresh/re-mount.
            if (orderIdParam) router.replace("/admin/orders");
          }}
          className="mb-4 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white"
        >
          ← Back to orders
        </button>

        <div className="mb-6">
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">{selected.order_number}</h1>
          <p className="mt-1 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
            <Tag severity={paymentStatusSeverity[selected.payment_status]}>
              payment: {selected.payment_status}
            </Tag>
            <Tag severity={deliveryStatusSeverity[selected.delivery_status]}>
              delivery: {selected.delivery_status}
            </Tag>
          </p>
          {/* ADR-026: the ambiguous-outcome case — cross-reference banner, plus "Mark as Delivered" instead of "Issue Voucher" (decision 4c: voucher issuance is deliberately never available from this state). */}
          {selected.delivery_status === "needs_review" && <NeedsReviewBanner order={selected} />}

          {/* ADR-017: one action for "fix a failed delivery" — defaults to resending the same package (the old plain "Retry Delivery" behavior), with the option to swap packages inside the modal. A failed or needs_review delivery can be resent (ADR-026 decision 4b) — mirrors the backend guard exactly. */}
          {(selected.delivery_status === "failed" || selected.delivery_status === "needs_review") && (
            <div className="mt-3 flex flex-wrap items-center gap-3">
              <Button size="small" onClick={() => setResendModalOpen(true)}>
                Resend Delivery…
              </Button>
              {/* ADR-073 decision 7: a wallet-owned order gets "Refund to Wallet" INSTEAD of "Issue Voucher" — never both, Voucher's email-keyed mechanism has no meaning for a B2B wallet account. */}
              {selected.delivery_status === "failed" && selected.wallet_reseller && !selected.wallet_refunded && (
                <Button size="small" variant="outlined" disabled={refundingToWallet} onClick={handleRefundToWallet}>
                  {refundingToWallet ? "Refunding…" : "Refund to Wallet…"}
                </Button>
              )}
              {/* ADR-004/ORD-7: the other resolution path — hidden once a voucher has already been issued for this order (at most one, enforced by a real unique index on the backend, not just this check), and never shown for needs_review at all (ADR-026 decision 4c). */}
              {selected.delivery_status === "failed" && !selected.wallet_reseller && !selected.voucher && (
                <Button size="small" variant="outlined" onClick={() => setVoucherModalOpen(true)}>
                  Issue Voucher…
                </Button>
              )}
              {/* ADR-026 decision 4a — the one needs_review exit that isn't a retry. */}
              {selected.delivery_status === "needs_review" && (
                <Button size="small" variant="outlined" onClick={() => setMarkDeliveredModalOpen(true)}>
                  Mark as Delivered…
                </Button>
              )}
              {resendMessage && <span className="text-sm text-gray-500 dark:text-gray-400">{resendMessage}</span>}
              {voucherMessage && <span className="text-sm text-gray-500 dark:text-gray-400">{voucherMessage}</span>}
              {refundMessage && <span className="text-sm text-gray-500 dark:text-gray-400">{refundMessage}</span>}
            </div>
          )}
          {/* Rendered outside the failed/needs_review-gated block above,
              deliberately — unlike resend/voucher, a successful Mark as
              Delivered moves delivery_status to "delivered" in the same
              render that sets this message, which would otherwise
              unmount it before an admin ever saw it (found live via the
              ADR-023 admin-mark-delivered E2E spec). */}
          {markDeliveredMessage && (
            <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">{markDeliveredMessage}</p>
          )}
          {selected.voucher && (
            <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">
              Voucher <span className="font-medium text-gray-800 dark:text-white/90">{selected.voucher.code}</span> already issued for this order.
            </p>
          )}
          {selected.wallet_refunded && (
            <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">
              Already refunded to <span className="font-medium text-gray-800 dark:text-white/90">{selected.wallet_reseller?.business_name}</span>&apos;s wallet.
            </p>
          )}
        </div>

        <OrderDetailCards order={selected} />

        {/* ADR-017 decision #4: every resend attempt, not just the latest supplier_response. */}
        <DeliveryLogsTable attempts={selected.resend_attempts} />
      </div>

      {session && (
        <>
          <ResendDeliveryModal
            isOpen={resendModalOpen}
            onClose={() => setResendModalOpen(false)}
            onResent={handleResent}
            order={selected}
            token={session.token}
          />
          <IssueVoucherModal
            isOpen={voucherModalOpen}
            onClose={() => setVoucherModalOpen(false)}
            onIssued={handleVoucherIssued}
            order={selected}
            token={session.token}
          />
          <MarkDeliveredModal
            isOpen={markDeliveredModalOpen}
            onClose={() => setMarkDeliveredModalOpen(false)}
            onConfirmed={handleMarkedDelivered}
            order={selected}
            token={session.token}
          />
        </>
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
          <DataTable data={page?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Order #</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Customer</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Game / Package</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Final Amount</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Payment</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Delivery</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const order = item as unknown as OrderListItem;

                    return (
                      <DataTableRow key={order.id}>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{order.order_number}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{order.customer_email}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {order.game?.name ?? "—"}
                          {order.package?.name && <span className="text-theme-xs text-gray-400"> · {order.package.name}</span>}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{formatRm(order.final_amount)}</DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={paymentStatusSeverity[order.payment_status]}>{order.payment_status}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Tag severity={deliveryStatusSeverity[order.delivery_status]}>{order.delivery_status}</Tag>
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {new Date(order.created_at).toLocaleString()}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          <Button size="small" variant="outlined" onClick={() => session && openOrder(session.token, order.id)}>
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
