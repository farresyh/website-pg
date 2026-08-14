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
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Badge from "@/components/ui/badge/Badge";
import Button from "@/components/ui/button/Button";
import { getClientSession } from "@/lib/session";
import type { SessionPayload } from "@/lib/auth";
import { ApiError } from "@/lib/api-client";
import { type OrderListItem, type OrderPage } from "@/lib/orders";
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
import OrderDetailCards from "@/components/orders/OrderDetailCards";
import DeliveryLogsTable from "@/components/orders/DeliveryLogsTable";
import CreateSandboxOrderModal from "@/components/middleware/CreateSandboxOrderModal";

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
  // ADR-026's needs_review is a real-Gamevion-ambiguity concept — the
  // sandbox's synchronous FakeSupplierAdapter flow never produces it,
  // this key exists only to satisfy the shared OrderListItem type.
  needs_review: "warning",
};

export default function SandboxOrdersPage() {
  const router = useRouter();
  const [session, setSession] = useState<SessionPayload | null>(null);

  const [search, setSearch] = useState("");
  const [pageNumber, setPageNumber] = useState(1);
  const [page, setPage] = useState<OrderPage | null>(null);
  const [error, setError] = useState<string | null>(null);

  const [selected, setSelected] = useState<SandboxOrderDetail | null>(null);
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [resendModalOpen, setResendModalOpen] = useState(false);
  const [resendMessage, setResendMessage] = useState<string | null>(null);

  async function refreshList() {
    if (!session) return;
    listSandboxOrders(session.token, { search: search || undefined, page: pageNumber })
      .then(setPage)
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load test orders."));
  }

  async function openOrder(token: string, id: number) {
    setSelected(null);
    setResendMessage(null);
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
    setSession(s);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    setPageNumber(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

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
              <Badge size="sm" color="warning">sandbox</Badge>
              <Badge size="sm" color={paymentStatusColor[selected.payment_status]}>
                payment: {selected.payment_status}
              </Badge>
              <Badge size="sm" color={deliveryStatusColor[selected.delivery_status]}>
                delivery: {selected.delivery_status}
              </Badge>
            </p>
            <div className="mt-3 flex flex-wrap items-center gap-3">
              {selected.delivery_status === "failed" && (
                <Button size="sm" onClick={() => setResendModalOpen(true)}>
                  Resend Delivery…
                </Button>
              )}
              <Button size="sm" variant="outline" onClick={() => handleDelete(selected.id)}>
                Delete Test Order
              </Button>
              {resendMessage && <span className="text-sm text-gray-500 dark:text-gray-400">{resendMessage}</span>}
            </div>
          </div>

          <OrderDetailCards order={selected} />
          <DeliveryLogsTable attempts={selected.resend_attempts} />
        </div>

        {session && (
          <ResendDeliveryModal
            isOpen={resendModalOpen}
            onClose={() => setResendModalOpen(false)}
            onResent={handleResent}
            order={selected}
            token={session.token}
            sandbox
          />
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
          <Button size="sm" variant="outline" onClick={handleDeleteAll}>
            Delete All Test Orders
          </Button>
          <Button size="sm" onClick={() => setCreateModalOpen(true)}>
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
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Order #</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Game / Package</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Final Amount</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Delivery</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {page?.data.map((order) => (
                <TableRow key={order.id}>
                  <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{order.order_number}</TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                    {order.game?.name ?? "—"}
                    {order.package?.name && <span className="text-theme-xs text-gray-400"> · {order.package.name}</span>}
                  </TableCell>
                  <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{formatRm(order.final_amount)}</TableCell>
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
            <Button size="sm" variant="outline" disabled={page.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button size="sm" variant="outline" disabled={page.current_page >= page.last_page} onClick={() => setPageNumber((p) => p + 1)}>
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
