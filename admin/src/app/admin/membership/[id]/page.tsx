"use client";

/**
 * ADR-068 decisions 14/15 — the per-member detail. Reached by "View" on
 * the Members registry (list→detail, same as /admin/orders). Read-only:
 * the member's current state, its fee-payment history (each linked to
 * the ledger entry it booked), its self-serve checkout attempts
 * *including the pending/failed ones the flat registry can't show* (the
 * support gap this view exists for), and a member-orders summary.
 *
 * ADR-038: PrimeReact-Tailwind only (Tag / DataTable), no old TailAdmin
 * primitives.
 */

import { useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { Tag } from "@/components/ui/tag";
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
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  getMembershipDetail,
  formatMemberRm,
  formatMemberDate,
  type MembershipDetail,
  type MemberCheckoutAttemptStatus,
} from "@/lib/membership";

const attemptSeverity: Record<MemberCheckoutAttemptStatus, "success" | "info" | "danger" | "secondary"> = {
  paid: "success",
  pending: "info",
  failed: "danger",
  expired: "secondary",
};

const BACK = (
  <Link href="/admin/membership" className="text-theme-sm text-brand-500 hover:underline">
    ← Back to Membership
  </Link>
);

function StatCard({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
      <p className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</p>
      <div className="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{children}</div>
    </div>
  );
}

export default function MemberDetailPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const session = useClientSession();
  const id = Number(params.id);

  const [detail, setDetail] = useState<MembershipDetail | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!getClientSession()) router.replace("/login");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!session || !Number.isFinite(id)) return;
    getMembershipDetail(session.token, id)
      .then(setDetail)
      .catch((err: unknown) => {
        if (err instanceof ApiError && err.status === 404) {
          setNotFound(true);
          return;
        }
        setError(err instanceof ApiError ? err.message : "Could not load this member.");
      });
  }, [session, id]);

  if (notFound) {
    return (
      <div>
        {BACK}
        <p className="mt-4 text-sm text-gray-500 dark:text-gray-400">That member no longer exists.</p>
      </div>
    );
  }

  if (error) {
    return (
      <div>
        {BACK}
        <p className="mt-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      </div>
    );
  }

  if (!detail) {
    return (
      <div>
        {BACK}
        <p className="mt-4 text-sm text-gray-500 dark:text-gray-400">Loading…</p>
      </div>
    );
  }

  const { member, fee_payments, checkout_attempts, orders_summary } = detail;

  return (
    <div>
      {BACK}

      <div className="mt-2 mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">{member.email}</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {member.brand_name ?? `brand #${member.affiliate_id}`} · member since {formatMemberDate(member.member_since)}
          </p>
        </div>
        <Tag severity={member.status === "active" ? "success" : "secondary"}>{member.status}</Tag>
      </div>

      {/* Current state */}
      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Tier">{member.plan_name ?? `plan #${member.plan_id}`}</StatCard>
        <StatCard label="Quota Used">
          {formatMemberRm(member.quota_used_sen)} / {formatMemberRm(member.quota_total_sen)}
        </StatCard>
        <StatCard label="Cycle Started">{formatMemberDate(member.cycle_started_at)}</StatCard>
        <StatCard label="Renews / Expires">{formatMemberDate(member.expires_at)}</StatCard>
      </div>

      {/* Fee Payments */}
      <section className="mb-6">
        <h2 className="mb-1 text-base font-semibold text-gray-800 dark:text-white/90">Fee Payments</h2>
        <p className="mb-3 text-theme-xs text-gray-500 dark:text-gray-400">
          Every membership fee booked for this member — admin-recorded and self-serve alike. Each links to its
          <code className="mx-1 rounded bg-gray-100 px-1 py-0.5 dark:bg-white/10">ledger_entries</code> row.
        </p>
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="max-w-full overflow-x-auto">
            <DataTable data={fee_payments} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Tier</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Amount</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Source</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Reason</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ledger #</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const f = item as unknown as MembershipDetail["fee_payments"][number];
                      return (
                        <DataTableRow key={f.id}>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatMemberDate(f.date)}</DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{f.plan_name ?? "—"}</DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">{formatMemberRm(f.amount_sen)}</DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{f.source}</DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{f.reason ?? "—"}</DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-400 dark:text-gray-500">{f.ledger_entry_id ?? "—"}</DataTableCell>
                        </DataTableRow>
                      );
                    }}
                  </DataTableTBody>
                </DataTableTable>
              </DataTableTableContainer>
            </DataTable>
            {fee_payments.length === 0 && (
              <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No fee payments recorded.</p>
            )}
          </div>
        </div>
      </section>

      {/* Checkout Attempts */}
      <section className="mb-6">
        <h2 className="mb-1 text-base font-semibold text-gray-800 dark:text-white/90">Self-Serve Checkout Attempts</h2>
        <p className="mb-3 text-theme-xs text-gray-500 dark:text-gray-400">
          Every self-serve subscription this member started — including <span className="font-medium">pending</span>,{" "}
          <span className="font-medium">failed</span> and <span className="font-medium">expired</span> ones. Use this to
          answer &ldquo;I paid but I&apos;m not active&rdquo;.
        </p>
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="max-w-full overflow-x-auto">
            <DataTable data={checkout_attempts} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ref</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Tier</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Fee</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Charged</DataTableTHeadCell>
                      <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">Channel</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const a = item as unknown as MembershipDetail["checkout_attempts"][number];
                      return (
                        <DataTableRow key={a.id}>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatMemberDate(a.date)}</DataTableCell>
                          <DataTableCell className="px-5 py-4 font-mono text-theme-xs text-gray-500 dark:text-gray-400">{a.subscription_number}</DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{a.plan_name ?? "—"}</DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm">
                            <Tag severity={attemptSeverity[a.status]}>{a.status}</Tag>
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatMemberRm(a.fee_sen)}</DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{formatMemberRm(a.total_charged_sen)}</DataTableCell>
                          <DataTableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">{a.channel_code}</DataTableCell>
                        </DataTableRow>
                      );
                    }}
                  </DataTableTBody>
                </DataTableTable>
              </DataTableTableContainer>
            </DataTable>
            {checkout_attempts.length === 0 && (
              <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">No self-serve attempts.</p>
            )}
          </div>
        </div>
      </section>

      {/* Member Orders summary */}
      <section>
        <div className="mb-3 flex items-center justify-between gap-3">
          <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">Member Orders</h2>
          <Link
            href={`/admin/orders?search=${encodeURIComponent(member.email)}`}
            className="text-theme-sm text-brand-500 hover:underline"
          >
            View orders →
          </Link>
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <StatCard label="Member-priced orders">{orders_summary.count}</StatCard>
          <StatCard label="Total spent">{formatMemberRm(orders_summary.total_spent_sen)}</StatCard>
          <StatCard label="Margin given up">{formatMemberRm(orders_summary.margin_forgone_sen)}</StatCard>
        </div>
      </section>
    </div>
  );
}
