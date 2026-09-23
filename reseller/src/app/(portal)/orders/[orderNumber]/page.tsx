"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getOrder, type OrderDetail } from "@/lib/portal";
import {
  formatRm,
  formatDateTime,
  humanize,
  paymentSeverity,
  deliverySeverity,
} from "@/lib/format";
import { PageHeader, Panel, StatusTag, ErrorNote } from "@/components/ui";

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-6 px-5 py-3 text-theme-sm">
      <span className="text-gray-500 dark:text-gray-400">{label}</span>
      <span className="text-right font-medium text-gray-800 dark:text-white/90">
        {value}
      </span>
    </div>
  );
}

export default function OrderDetailPage() {
  const params = useParams<{ orderNumber: string }>();
  const session = useClientSession();
  const ownerType = session?.owner_type ?? "affiliate";
  const [order, setOrder] = useState<OrderDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const current = getClientSession();
    if (!current || !params.orderNumber) return;

    getOrder(current.token, ownerType, params.orderNumber)
      .then(setOrder)
      .catch((err: unknown) => {
        if (err instanceof ApiError && err.status === 404) {
          setError("Order not found.");
        } else {
          setError(err instanceof ApiError ? err.message : "Could not load the order.");
        }
      });
  }, [params.orderNumber, ownerType]);

  return (
    <div>
      <PageHeader
        title={params.orderNumber}
        subtitle="Order detail"
        action={
          <Link
            href="/orders"
            className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
          >
            Back to orders
          </Link>
        }
      />

      {error && <ErrorNote message={error} />}
      {!order && !error && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
      )}

      {order && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Panel title="Order">
            <div className="divide-y divide-gray-100 dark:divide-gray-800">
              {ownerType === "affiliate" && (
                <Row label="Reference" value={order.reference_number ?? "—"} />
              )}
              <Row label="Game" value={order.game?.name ?? "—"} />
              <Row label="Package" value={order.package_name ?? "—"} />
              <Row label="Player ID" value={order.player_id ?? "—"} />
              <Row label="Server ID" value={order.server_id ?? "—"} />
              <Row
                label="Payment"
                value={
                  <StatusTag severity={paymentSeverity(order.payment_status)}>
                    {order.payment_status}
                  </StatusTag>
                }
              />
              <Row
                label="Delivery"
                value={
                  <StatusTag severity={deliverySeverity(order.delivery_status)}>
                    {humanize(order.delivery_status)}
                  </StatusTag>
                }
              />
              {ownerType === "affiliate" && order.has_compensation_voucher && (
                <Row label="Compensation" value="Voucher Issued" />
              )}
              {ownerType === "affiliate" && order.has_voucher_restored && (
                <Row label="Voucher" value="Restored" />
              )}
              {ownerType === "affiliate" && (
                <Row label="Payment method" value={order.payment_method ?? "—"} />
              )}
              {ownerType === "affiliate" && (
                <Row label="Paid at" value={formatDateTime(order.paid_at ?? null)} />
              )}
              <Row label="Delivered at" value={formatDateTime(order.delivered_at)} />
            </div>
          </Panel>

          <div className="space-y-6">
            <Panel title="Money">
              <div className="divide-y divide-gray-100 dark:divide-gray-800">
                <Row label="Total" value={formatRm(order.final_amount)} />
                {ownerType === "reseller" && order.wallet_refund && (
                  <>
                    <Row label="Wallet refunded" value={formatRm(order.wallet_refund.amount_sen)} />
                    <Row label="Refunded at" value={formatDateTime(order.wallet_refund.refunded_at)} />
                  </>
                )}
                {ownerType === "affiliate" && (
                  <>
                    <Row
                      label="Voucher discount"
                      value={formatRm(order.voucher_discount ?? 0)}
                    />
                    <Row label="Transaction fee" value={formatRm(order.transaction_fee ?? 0)} />
                    <Row
                      label="Your markup"
                      value={`${order.affiliate_markup_pct ?? 0}%`}
                    />
                    <Row
                      label="Your margin"
                      value={formatRm(order.affiliate_profit ?? 0)}
                    />
                  </>
                )}
              </div>
            </Panel>

            {ownerType === "affiliate" && (
              <Panel title="Customer">
                <div className="divide-y divide-gray-100 dark:divide-gray-800">
                  <Row label="Email" value={order.customer_email ?? "—"} />
                  <Row label="Name" value={order.customer_name ?? "—"} />
                  <Row label="Phone" value={order.customer_phone ?? "—"} />
                </div>
              </Panel>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
