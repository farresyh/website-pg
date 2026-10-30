"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { getWithdrawals, type WithdrawalRow, type WithdrawalsResponse } from "@/lib/portal";
import { dateAndTime, formatRm, type StatusBadge } from "@/lib/format";
import { Badge, CardHead, ErrorNote, PageHeader, Panel, Skeleton } from "@/components/composites";
import { PortalIcon } from "@/components/shell/PortalIcon";
import { StepsCard } from "@/components/money/MoneyForm";

/**
 * Money out to the Affiliate's bank (ADR-059, WTH-1..5, ADR-132). PekanGame
 * opens one payout ticket on the 1st of each month for the whole balance
 * (minus the tier fee still to come); there is no request form. The ledger
 * is debited when an admin approves, not when the ticket opens.
 */
export default function WithdrawalPage() {
  const [data, setData] = useState<WithdrawalsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;
    let cancelled = false;
    getWithdrawals(session.token)
      .then((result) => {
        if (cancelled) return;
        setData(result);
        setError(null);
      })
      .catch((err: unknown) => !cancelled && setError(err instanceof ApiError ? err.message : "Could not load withdrawals."));
    return () => {
      cancelled = true;
    };
  }, []);

  const openRequest = data?.withdrawals.find((w) => w.status === "pending" || w.status === "approved") ?? null;
  const bank = data?.prefill.bank_name ? data.prefill : null;
  const payout = data?.payout ?? null;

  return (
    <div>
      <PageHeader title="Withdrawal" subtitle="PekanGame pays your earnings to your bank once a month." />
      {error && <ErrorNote message={error} />}

      <div className="mb-4 grid items-start gap-4 xl:grid-cols-2">
        <Panel>
          <CardHead
            title="Monthly payout"
            description={data ? `Earnings balance: ${formatRm(data.balance)}` : undefined}
            divided
          />
          {!data || !payout ? (
            <div className="p-5">
              <Skeleton className="h-48" />
            </div>
          ) : (
            <div className="flex flex-col gap-4 p-5 text-[13px] text-ink-muted">
              {!payout.has_bank_details && (
                <p className="rounded-md border border-warning-ink/30 bg-warning-surface px-3.5 py-3 text-warning-ink">
                  Add your bank details in{" "}
                  <Link href="/profile" className="font-semibold underline">
                    Profile
                  </Link>
                  . Without them no payout is opened for you.
                </p>
              )}
              <p>
                On the <b className="text-ink">1st of every month</b> PekanGame opens a payout for your whole earnings balance, and transfers it to your
                bank between the <b className="text-ink">1st and the 7th</b>. The minimum is <b className="text-ink">{formatRm(payout.min_payout_sen)}</b>;
                a smaller balance carries over to the next month.
              </p>
              {openRequest && (
                <div className="flex flex-col gap-1.5 rounded-md border border-border bg-subtle px-3.5 py-3">
                  <span>
                    <b className="text-ink">{formatRm(openRequest.amount)}</b> is scheduled for transfer to {openRequest.bank_name}{" "}
                    {maskAccount(openRequest.bank_account_no)}.
                  </span>
                  <span className="w-fit">
                    <Badge tone={statusBadge(openRequest).tone}>{statusBadge(openRequest).label}</Badge>
                  </span>
                </div>
              )}
              {payout.reserved_for_fee_sen > 0 && (
                <p>
                  <b className="text-ink">{formatRm(payout.reserved_for_fee_sen)}</b> stays in your balance for your tier fee
                  {payout.fee_due_at ? ` (due ${dateAndTime(payout.fee_due_at).date})` : ""}.
                </p>
              )}
              {bank && (
                <div className="flex items-center gap-3 rounded-md border border-border bg-subtle px-3 py-2.5">
                  <span className="grid h-8 w-11 shrink-0 place-items-center rounded-sm border border-border bg-surface text-ink-muted">
                    <PortalIcon name="bank" className="h-4 w-4" />
                  </span>
                  <span className="flex min-w-0 flex-1 flex-col">
                    <b className="truncate text-[13px] font-semibold text-ink">
                      Paid to {bank.bank_name} {maskAccount(bank.bank_account_no)}
                    </b>
                    <span className="truncate text-xs text-ink-subtle">{bank.bank_account_holder}</span>
                  </span>
                  <Link href="/profile" className="text-[13px] font-semibold text-cyan-ink hover:underline">
                    Change
                  </Link>
                </div>
              )}
            </div>
          )}
        </Panel>

        <StepsCard
          title="What happens next"
          steps={[
            { title: "Pending", body: "PekanGame opened your payout and will check it. Your earnings are not reduced yet." },
            { title: "Approved, then Completed", body: "On approval the amount leaves your earnings and is sent to your bank. Completed shows the date it was paid." },
            { title: "Rejected", body: "Nothing leaves your earnings, the reason is shown here, and the amount joins next month's payout." },
          ]}
        />
      </div>

      <Panel>
        <CardHead title="Your payouts" divided />
        {!data ? (
          <div className="p-5">
            <Skeleton className="h-24" />
          </div>
        ) : data.withdrawals.length === 0 ? (
          <p className="p-8 text-center text-sm text-ink-subtle">No payouts yet. The first one opens on the 1st of the month once your balance reaches the minimum.</p>
        ) : (
          <ul className="divide-y divide-border">
            {data.withdrawals.map((w) => {
              const badge = statusBadge(w);
              const requested = dateAndTime(w.created_at);
              return (
                <li key={w.id} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-5 py-3 md:grid-cols-[64px_120px_minmax(0,1fr)_minmax(0,1.6fr)_140px]">
                  <span className="hidden font-mono text-[13px] text-ink-muted md:block">#{w.id}</span>
                  <span className="text-sm font-semibold text-ink md:text-right">{formatRm(w.amount)}</span>
                  <span className="hidden truncate text-[13px] text-ink-muted md:block">
                    {w.bank_name} {maskAccount(w.bank_account_no)}
                  </span>
                  <span className="col-span-2 flex min-w-0 flex-col items-start gap-0.5 md:col-span-1">
                    <Badge tone={badge.tone}>{badge.label}</Badge>
                    <span className="max-w-full truncate text-xs text-ink-subtle">{statusNote(w)}</span>
                  </span>
                  <span className="row-start-1 text-right text-xs text-ink-subtle md:row-start-auto md:text-left">
                    <span className="block text-[13px] font-medium text-ink-muted">{requested.date}</span>
                    {requested.time}
                  </span>
                </li>
              );
            })}
          </ul>
        )}
      </Panel>
    </div>
  );
}

function statusBadge(w: WithdrawalRow): StatusBadge {
  return (
    {
      pending: { tone: "warning", label: "Pending" },
      approved: { tone: "info", label: "Approved" },
      completed: { tone: "success", label: "Completed" },
      rejected: { tone: "danger", label: "Rejected" },
    } as const
  )[w.status];
}

function statusNote(w: WithdrawalRow): string {
  if (w.status === "pending") return "Waiting for PekanGame to approve";
  if (w.status === "approved") return "Approved, being transferred";
  if (w.status === "completed") return w.processed_at ? `Paid ${dateAndTime(w.processed_at).date}` : "Paid";
  return w.admin_note ?? "Rejected";
}

/** Only the last 4 digits of an account number are shown. */
function maskAccount(no: string | null): string {
  if (!no) return "";
  const digits = no.replace(/\s/g, "");
  return digits.length > 4 ? `••••${digits.slice(-4)}` : digits;
}
