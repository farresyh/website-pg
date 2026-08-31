"use client";

import { useEffect, useState } from "react";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { getSubscription, type SubscriptionResponse } from "@/lib/portal";
import {
  formatRm,
  formatDate,
  formatDateTime,
  subscriptionSeverity,
} from "@/lib/format";
import {
  PageHeader,
  Panel,
  StatusTag,
  ErrorNote,
  EmptyRow,
} from "@/components/ui";

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

export default function SubscriptionPage() {
  const [data, setData] = useState<SubscriptionResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;

    getSubscription(session.token)
      .then(setData)
      .catch((err: unknown) =>
        setError(
          err instanceof ApiError ? err.message : "Could not load subscription.",
        ),
      );
  }, []);

  const sub = data?.subscription;

  return (
    <div>
      <PageHeader
        title="Subscription"
        subtitle="Your wholesale-tier plan. Tier changes are handled by the platform team."
      />

      {error && <ErrorNote message={error} />}
      {!data && !error && (
        <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
      )}

      {data && !sub && (
        <Panel>
          <EmptyRow>
            You are not on a wholesale tier — your storefront prices at the
            standard guest rate.
          </EmptyRow>
        </Panel>
      )}

      {sub && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Panel title="Current plan">
            <div className="divide-y divide-gray-100 dark:divide-gray-800">
              <Row label="Tier" value={sub.tier_name} />
              <Row
                label="Status"
                value={
                  <StatusTag severity={subscriptionSeverity(sub.status)}>
                    {sub.status}
                  </StatusTag>
                }
              />
              <Row label="Monthly fee" value={formatRm(sub.monthly_fee_sen)} />
              <Row
                label="Wholesale markup"
                value={`${sub.wholesale_markup_percent}%`}
              />
              <Row
                label="Period started"
                value={formatDate(sub.current_period_started_at)}
              />
              <Row label="Next charge" value={formatDate(sub.next_charge_at)} />
              {sub.status === "grace" && sub.grace_until && (
                <Row
                  label="Grace ends"
                  value={
                    <span className="text-warning-600 dark:text-warning-500">
                      {formatDate(sub.grace_until)}
                    </span>
                  }
                />
              )}
            </div>
          </Panel>

          <Panel title="Charge history">
            <div className="divide-y divide-gray-100 dark:divide-gray-800">
              {data.charge_history.length === 0 && (
                <EmptyRow>No fees charged yet.</EmptyRow>
              )}
              {data.charge_history.map((row) => (
                <div
                  key={row.id}
                  className="flex justify-between px-5 py-3 text-theme-sm"
                >
                  <span className="text-gray-500 dark:text-gray-400">
                    {formatDateTime(row.charged_at)}
                  </span>
                  <span className="font-medium text-error-600 dark:text-error-500">
                    {formatRm(row.amount)}
                  </span>
                </div>
              ))}
            </div>
          </Panel>
        </div>
      )}
    </div>
  );
}
