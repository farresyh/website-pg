"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { createWithdrawal, getWithdrawals, type WithdrawalRow, type WithdrawalsResponse } from "@/lib/portal";
import { dateAndTime, formatRm, toSen, type StatusBadge } from "@/lib/format";
import { Badge, CardHead, ErrorNote, PageHeader, Panel, Skeleton } from "@/components/composites";
import { Button } from "@/components/ui/button";
import { PortalIcon } from "@/components/shell/PortalIcon";
import { AmountField, StepsCard } from "@/components/money/MoneyForm";

/**
 * Money out to the Affiliate's bank (ADR-059, WTH-1..5). One request open at
 * a time; it always pays the bank details saved in Profile. The ledger is
 * debited when an admin approves, not when the request is made.
 */
export default function WithdrawalPage() {
  const [data, setData] = useState<WithdrawalsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);
  const [amount, setAmount] = useState("");

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
  }, [reloadKey]);

  const openRequest = data?.withdrawals.find((w) => w.status === "pending" || w.status === "approved") ?? null;
  const bank = data?.prefill.bank_name ? data.prefill : null;
  const all = data ? (data.balance / 100).toFixed(2) : "";

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError(null);
    const amountSen = toSen(amount);
    if (amountSen === null) {
      setFormError("Enter an amount in ringgit, with at most 2 decimals.");
      return;
    }
    const session = getClientSession();
    if (!session) return;
    setSubmitting(true);
    try {
      await createWithdrawal(session.token, { amount: amountSen });
      setAmount("");
      setReloadKey((k) => k + 1);
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : "Could not submit the request.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div>
      <PageHeader title="Withdrawal" subtitle="Send your earnings to your bank. PekanGame reviews each request before paying it." />
      {error && <ErrorNote message={error} />}

      <div className="mb-4 grid items-start gap-4 xl:grid-cols-2">
        <Panel>
          <CardHead
            title={openRequest ? "Request in progress" : "New withdrawal"}
            description={data ? `Available: ${formatRm(data.balance)}` : undefined}
            divided
          />
          {!data ? (
            <div className="p-5">
              <Skeleton className="h-48" />
            </div>
          ) : openRequest ? (
            <div className="flex flex-col gap-3 p-5 text-[13px] text-ink-muted">
              <p>
                Request #{openRequest.id} for <b className="text-ink">{formatRm(openRequest.amount)}</b> is{" "}
                {openRequest.status === "pending" ? "waiting for approval" : "approved and being transferred"}. You can request another once it is
                completed or rejected.
              </p>
              <span className="w-fit">
                <Badge tone={statusBadge(openRequest).tone}>{statusBadge(openRequest).label}</Badge>
              </span>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="flex flex-col gap-4 p-5">
              {formError && <ErrorNote message={formError} />}
              <AmountField
                id="withdraw-amount"
                value={amount}
                onChange={setAmount}
                quick={[
                  { label: "RM 50", value: "50" },
                  { label: `All (${formatRm(data.balance)})`, value: all },
                ]}
              />
              <div className="flex flex-col gap-2">
                <span className="text-[13px] font-semibold text-ink">Paid to</span>
                {bank ? (
                  <div className="flex items-center gap-3 rounded-md border border-border bg-subtle px-3 py-2.5">
                    <span className="grid h-8 w-11 shrink-0 place-items-center rounded-sm border border-border bg-surface text-ink-muted">
                      <PortalIcon name="bank" className="h-4 w-4" />
                    </span>
                    <span className="flex min-w-0 flex-1 flex-col">
                      <b className="truncate text-[13px] font-semibold text-ink">
                        {bank.bank_name} {maskAccount(bank.bank_account_no)}
                      </b>
                      <span className="truncate text-xs text-ink-subtle">{bank.bank_account_holder}</span>
                    </span>
                    <Link href="/profile" className="text-[13px] font-semibold text-cyan-ink hover:underline">
                      Change
                    </Link>
                  </div>
                ) : (
                  <p className="rounded-md border border-warning-ink/30 bg-warning-surface px-3.5 py-3 text-[13px] text-warning-ink">
                    Add your bank details in{" "}
                    <Link href="/profile" className="font-semibold underline">
                      Profile
                    </Link>{" "}
                    before requesting a withdrawal.
                  </p>
                )}
              </div>
              <Button type="submit" disabled={submitting || !bank} className="h-11 w-full text-sm">
                {submitting ? "Sending request…" : "Request withdrawal"}
              </Button>
              <p className="flex items-center justify-center gap-1.5 text-center text-xs text-ink-subtle">
                <PortalIcon name="info" className="h-4 w-4" />
                Your earnings go down only when PekanGame approves the request.
              </p>
            </form>
          )}
        </Panel>

        <StepsCard
          title="What happens next"
          steps={[
            { title: "Pending", body: "PekanGame checks your request. Your earnings are not reduced yet." },
            { title: "Approved, then Completed", body: "On approval the amount leaves your earnings and is sent to your bank. Completed shows the date it was paid." },
            { title: "Rejected", body: "Nothing leaves your earnings, and the reason is shown here." },
          ]}
        />
      </div>

      <Panel>
        <CardHead title="Your withdrawals" divided />
        {!data ? (
          <div className="p-5">
            <Skeleton className="h-24" />
          </div>
        ) : data.withdrawals.length === 0 ? (
          <p className="p-8 text-center text-sm text-ink-subtle">No withdrawal requests yet.</p>
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
