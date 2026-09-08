"use client";

/**
 * ADR-018: a middleware-only tool for exercising the real Order
 * lifecycle (status transitions, OrderResendService's validation
 * logic, order_resend_attempts audit trail, this exact UI) without
 * touching real data or real money. Every order here is created
 * directly at delivery_status=failed (decision #4) so it's instantly
 * usable with the same Resend Delivery flow /admin/orders uses —
 * decision #8's shared components (OrderDetailCards, DeliveryLogsTable,
 * ResendDeliveryModal in sandbox mode) render identically to that
 * screen, against this page's own is_test-scoped endpoints.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
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
import { type OrderListItem, type OrderPage, type OrderDetail } from "@/lib/orders";
import {
  type SandboxOrderDetail,
  type CreateSandboxOrderValues,
  listSandboxOrders,
  getSandboxOrder,
  createSandboxOrder,
  deleteSandboxOrder,
  deleteAllSandboxOrders,
} from "@/lib/sandboxOrders";
import ResendDeliveryModal from "@/components/orders/ResendDeliveryModal";
import MarkDeliveredModal from "@/components/orders/MarkDeliveredModal";
import NeedsReviewBanner from "@/components/orders/NeedsReviewBanner";
import OrderDetailCards from "@/components/orders/OrderDetailCards";
import DeliveryLogsTable from "@/components/orders/DeliveryLogsTable";
import CreateSandboxOrderModal from "@/components/middleware/CreateSandboxOrderModal";

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
  // ADR-026 — reachable here too: resend()'s error_code field is
  // free-text, so typing "duplicate_reference" reaches needs_review
  // through the same OrderFulfillmentService::fulfill() logic a real
  // ambiguous Gamevion response would.
  needs_review: "warn",
  // ADR-032 — sandbox orders can't structurally reach this (FakeSupplierAdapter
  // only ever simulates success/failure, never an async Pending), TS
  // just needs the key to satisfy the shared OrderListItem type.
  pending: "info",
};

export default function SandboxOrdersPage() {
  const router = useRouter();
  const session = useClientSession();

  const [search, setSearch] = useState("");
  const [pageNumber, setPageNumber] = useState(1);
  // Adjusted during render, not in an effect — see orders/page.tsx for
  // why (resets pagination to 1 whenever the search term changes).
  const [paginationSearchKey, setPaginationSearchKey] = useState(search);
  if (paginationSearchKey !== search) {
    setPaginationSearchKey(search);
    setPageNumber(1);
  }
  const [page, setPage] = useState<OrderPage | null>(null);
  const [error, setError] = useState<string | null>(null);

  const [selected, setSelected] = useState<SandboxOrderDetail | null>(null);
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [resendModalOpen, setResendModalOpen] = useState(false);
  const [resendMessage, setResendMessage] = useState<string | null>(null);
  const [markDeliveredModalOpen, setMarkDeliveredModalOpen] = useState(false);
  const [markDeliveredMessage, setMarkDeliveredMessage] = useState<string | null>(null);

  async function refreshList() {
    if (!session) return;
    listSandboxOrders(session.token, { search: search || undefined, page: pageNumber })
      .then(setPage)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load test orders."));
  }

  async function openOrder(token: string, id: number) {
    setSelected(null);
    setResendMessage(null);
    setMarkDeliveredMessage(null);
    try {
      setSelected(await getSandboxOrder(token, id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this test order.");
    }
  }

  async function refreshSelected() {
    if (!session || !selected) return;
    setSelected(await getSandboxOrder(session.token, selected.id));
  }

  function handleResent() {
    setResendMessage("Resend complete — see the outcome below and the new Delivery Logs entry.");
    refreshSelected();
  }

  function handleMarkedDelivered(updated: OrderDetail) {
    setMarkDeliveredMessage("Delivery confirmed manually.");
    // Always a sandbox row here — this handler is only ever passed to
    // the sandbox-mode MarkDeliveredModal below, which always resolves
    // via markSandboxOrderDelivered() (a SandboxOrderDetail response).
    setSelected(updated as SandboxOrderDetail);
  }

  async function handleCreate(values: CreateSandboxOrderValues) {
    if (!session) return;
    await createSandboxOrder(session.token, values);
    setCreateModalOpen(false);
    refreshList();
  }

  async function handleDelete(id: number) {
    if (!session) return;
    await deleteSandboxOrder(session.token, id);
    setSelected(null);
    refreshList();
  }

  async function handleDeleteAll() {
    if (!session) return;
    if (!confirm("Delete every test order? This cannot be undone.")) return;
    await deleteAllSandboxOrders(session.token);
    setSelected(null);
    refreshList();
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
    refreshList();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [session, search, pageNumber]);

  if (selected) {
    return (
      <>
        <div>
          <button
            onClick={() => {
              setSelected(null);
              // Sandbox resends are synchronous (unlike the real,
              // queued flow /admin/orders shows) — the list's delivery
              // badge would otherwise stay stale until the next
              // search/page change, which is avoidable here since the
              // outcome is already known by the time this is clicked.
              refreshList();
            }}
            className="mb-4 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white"
          >
            ← Back to test orders
          </button>

          <div className="mb-6">
            <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">{selected.order_number}</h1>
            <p className="mt-1 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
              <Tag severity="warn">sandbox</Tag>
              <Tag severity={paymentStatusSeverity[selected.payment_status]}>
                payment: {selected.payment_status}
              </Tag>
              <Tag severity={deliveryStatusSeverity[selected.delivery_status]}>
                delivery: {selected.delivery_status}
              </Tag>
            </p>
            {selected.delivery_status === "needs_review" && <NeedsReviewBanner order={selected} sandbox />}

            <div className="mt-3 flex flex-wrap items-center gap-3">
              {/* ADR-026 decision 4b's sandbox counterpart — the same retry mechanism resolves a needs_review test order, not just a failed one. */}
              {(selected.delivery_status === "failed" || selected.delivery_status === "needs_review") && (
                <Button size="small" onClick={() => setResendModalOpen(true)}>
                  Resend Delivery…
                </Button>
              )}
              {/* ADR-026 decision 4a's sandbox counterpart. */}
              {selected.delivery_status === "needs_review" && (
                <Button size="small" variant="outlined" onClick={() => setMarkDeliveredModalOpen(true)}>
                  Mark as Delivered…
                </Button>
              )}
              <Button size="small" variant="outlined" onClick={() => handleDelete(selected.id)}>
                Delete Test Order
              </Button>
              {resendMessage && <span className="text-sm text-gray-500 dark:text-gray-400">{resendMessage}</span>}
              {markDeliveredMessage && <span className="text-sm text-gray-500 dark:text-gray-400">{markDeliveredMessage}</span>}
            </div>
          </div>

          <OrderDetailCards order={selected} />
          <DeliveryLogsTable order={selected} attempts={selected.resend_attempts} />
        </div>

        {session && (
          <>
            <ResendDeliveryModal
              isOpen={resendModalOpen}
              onClose={() => setResendModalOpen(false)}
              onResent={handleResent}
              order={selected}
              token={session.token}
              sandbox
            />
            <MarkDeliveredModal
              isOpen={markDeliveredModalOpen}
              onClose={() => setMarkDeliveredModalOpen(false)}
              onConfirmed={handleMarkedDelivered}
              order={selected}
              token={session.token}
              sandbox
            />
          </>
        )}
      </>
    );
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Sandbox Test Orders</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            ADR-018 — real Order rows, fully isolated from real data and real money. Never visible on /admin/orders,
            never touches the real ledger, delivery is always simulated (FakeSupplierAdapter), never the real
            Gamevion.
          </p>
        </div>
        <div className="flex gap-2">
          <Button size="small" variant="outlined" onClick={handleDeleteAll}>
            Delete All Test Orders
          </Button>
          <Button size="small" onClick={() => setCreateModalOpen(true)}>
            Create Test Order…
          </Button>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-4">
        <input
          type="text"
          placeholder="Search order #…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="h-11 w-full max-w-sm rounded-lg border border-gray-300 px-4 py-2.5 text-sm shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
        />
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={page?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Order #</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Game / Package</DataTableTHeadCell>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Final Amount</DataTableTHeadCell>
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
                        <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                          {order.game?.name ?? "—"}
                          {order.package?.name && <span className="text-theme-xs text-gray-400"> · {order.package.name}</span>}
                        </DataTableCell>
                        <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{formatRm(order.final_amount)}</DataTableCell>
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
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No test orders yet.</p>
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

      {session && (
        <CreateSandboxOrderModal
          isOpen={createModalOpen}
          onClose={() => setCreateModalOpen(false)}
          onSubmit={handleCreate}
          token={session.token}
        />
      )}
    </div>
  );
}
