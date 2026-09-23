"use client";

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getDashboard, listOrders, type DashboardStats, type OrderListItem } from "@/lib/portal";
import { getWallet, type WalletResponse } from "@/lib/reseller-portal";
import {
  formatRm,
  formatDate,
  subscriptionSeverity,
} from "@/lib/format";
import { PageHeader, StatCard, Panel, StatusTag, ErrorNote } from "@/components/ui";

export default function DashboardPage() {
  const session = useClientSession();
  if (!session) return null;

  return session.owner_type === "reseller" ? <ResellerDashboard /> : <AffiliateDashboard />;
}

/**
 * ADR-072 decision 5 / PR-G build-time judgment call: no dedicated
 * `/api/reseller-portal/dashboard` endpoint exists (not called for by
 * the planning addendum's endpoint list) — this composes the wallet
 * balance (`GET /wallet`) and a recent-orders slice (`GET /orders`,
 * already paginated) client-side instead of adding a new backend
 * summary endpoint for a single screen.
 */
function ResellerDashboard() {
  const [wallet, setWallet] = useState<WalletResponse | null>(null);
  const [recentOrders, setRecentOrders] = useState<OrderListItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;

    Promise.all([getWallet(session.token), listOrders(session.token, "reseller", { page: 1 })])
      .then(([walletResult, ordersResult]) => {
        setWallet(walletResult);
        setRecentOrders(ordersResult.data.slice(0, 5));
      })
      .catch((err: unknown) =>
        setError(err instanceof ApiError ? err.message : "Could not load the dashboard."),
      );
  }, []);

  return (
    <div>
      <PageHeader title="Dashboard" subtitle="Your wallet at a glance." />

      {error && <ErrorNote message={error} />}
      {!wallet && !error && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
      )}

      {wallet && (
        <>
          <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <StatCard label="Wallet balance" value={formatRm(wallet.balance)} />
          </div>

          <Panel title="Recent orders">
            <div className="divide-y divide-gray-100 dark:divide-gray-800">
              {(recentOrders ?? []).map((order) => (
                <div
                  key={order.order_number}
                  className="flex items-center justify-between gap-4 px-5 py-3 text-theme-sm"
                >
                  <div>
                    <p className="font-medium text-gray-800 dark:text-white/90">
                      {order.order_number}
                    </p>
                    <p className="text-theme-xs text-gray-400">
                      {order.game?.name ?? "—"} · {order.package_name ?? "—"}
                    </p>
                  </div>
                  <div className="text-right">
                    <p className="font-medium text-gray-800 dark:text-white/90">
                      {formatRm(order.final_amount)}
                    </p>
                    <StatusTag severity={order.delivery_status === "delivered" ? "success" : "muted"}>
                      {order.delivery_status}
                    </StatusTag>
                    {order.wallet_refunded && (
                      <span className="ml-1"><StatusTag severity="info">Wallet Refunded</StatusTag></span>
                    )}
                  </div>
                </div>
              ))}
              {recentOrders !== null && recentOrders.length === 0 && (
                <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
                  No orders yet.
                </p>
              )}
            </div>
          </Panel>
        </>
      )}
    </div>
  );
}

function AffiliateDashboard() {
  const [data, setData] = useState<DashboardStats | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;

    getDashboard(session.token)
      .then(setData)
      .catch((err: unknown) =>
        setError(
          err instanceof ApiError ? err.message : "Could not load the dashboard.",
        ),
      );
  }, []);

  return (
    <div>
      <PageHeader
        title="Dashboard"
        subtitle="Your storefront at a glance."
      />

      {error && <ErrorNote message={error} />}

      {data === null && !error && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
      )}

      {data && (
        <>
          <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard
              label="Withdrawable earnings"
              value={formatRm(data.earnings_balance)}
            />
            <StatCard
              label="Sales today"
              value={formatRm(data.today.sales)}
              hint={`${data.today.orders} order${data.today.orders === 1 ? "" : "s"}`}
            />
            <StatCard
              label="Sales this month"
              value={formatRm(data.this_month.sales)}
              hint={`${data.this_month.orders} order${data.this_month.orders === 1 ? "" : "s"}`}
            />
            <StatCard
              label="Wholesale tier"
              value={data.subscription?.tier_name ?? "None"}
              hint={
                data.subscription
                  ? `next charge ${formatDate(data.subscription.next_charge_at)}`
                  : "guest pricing"
              }
            />
          </div>

          {data.subscription && (
            <Panel title="Subscription">
              <div className="flex flex-wrap items-center gap-x-8 gap-y-2 px-5 py-4 text-theme-sm text-gray-600 dark:text-gray-300">
                <span>
                  Status{" "}
                  <StatusTag severity={subscriptionSeverity(data.subscription.status)}>
                    {data.subscription.status}
                  </StatusTag>
                </span>
                <span>
                  Monthly fee{" "}
                  <span className="font-medium text-gray-800 dark:text-white/90">
                    {formatRm(data.subscription.monthly_fee_sen)}
                  </span>
                </span>
                {data.subscription.status === "grace" &&
                  data.subscription.grace_until && (
                    <span className="text-warning-600 dark:text-warning-500">
                      Grace ends {formatDate(data.subscription.grace_until)}
                    </span>
                  )}
              </div>
            </Panel>
          )}
        </>
      )}
    </div>
  );
}
