"use client";

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { getDashboard, type DashboardStats } from "@/lib/portal";
import {
  formatRm,
  formatDate,
  subscriptionSeverity,
} from "@/lib/format";
import { PageHeader, StatCard, Panel, StatusTag, ErrorNote } from "@/components/ui";

export default function DashboardPage() {
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
