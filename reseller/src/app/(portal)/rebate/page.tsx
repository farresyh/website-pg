"use client";

/**
 * ADR-139 decision 41: the Reseller's Rebate page. The monthly ladder for its
 * own tier ("reach RM X → reward RM Y", never a share, cap or profit:
 * decision 8), Claim buttons in order with "Claim all", the terms tick on the
 * first claim, the upcoming-table notice, unspent rebate and history. A claim
 * sends only the month and the step; the server pays what it computes.
 */

import { useEffect, useState } from "react";
import Link from "next/link";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import { claimRebate, getRebate } from "@/lib/reseller-portal";
import { currentKlPeriod, rebateEntryLabel, type RebatePage } from "@/lib/rebate";
import { dateAndTime, formatRm } from "@/lib/format";
import { Badge, BalanceHero, CardHead, EmptyState, ErrorNote, PageHeader, Panel, Skeleton, buttonClass } from "@/components/composites";
import { StepsCard } from "@/components/money/MoneyForm";
import { RebateMonthPanel, TermsDialog, TermsText, UpcomingLadderNotice } from "@/components/rebate/RebateParts";

type Pending = { period: string; queue: number[] };

export default function RebatePage() {
  const [data, setData] = useState<RebatePage | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [termsFor, setTermsFor] = useState<Pending | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;
    let cancelled = false;
    getRebate(session.token)
      .then((result) => {
        if (cancelled) return;
        setData(result);
        setError(null);
      })
      .catch((err: unknown) => !cancelled && setError(err instanceof ApiError ? err.message : "Could not load your rebate."));
    return () => {
      cancelled = true;
    };
  }, [reload]);

  function startClaim(period: string, queue: number[]) {
    if (!data) return;
    if (!data.terms.accepted) {
      setTermsFor({ period, queue });
      return;
    }
    void runClaims({ period, queue }, false);
  }

  /** Claims one step at a time, in order, and stops at the first refusal. */
  async function runClaims({ period, queue }: Pending, acceptTerms: boolean) {
    const session = getClientSession();
    if (!session) return;
    const [year, month] = period.split("-").map(Number) as [number, number];
    setBusy(period);
    let claimed = 0;
    let lastAmount = 0;
    let adjusted = false;
    let balance: number | null = null;
    try {
      for (const step of queue) {
        const result = await claimRebate(session.token, { year, month, step, accept_terms: acceptTerms || undefined });
        claimed += 1;
        lastAmount = result.amount_sen;
        adjusted ||= result.adjusted;
        balance = result.balance_sen;
      }
      setTermsFor(null);
    } catch (err) {
      if (err instanceof ApiError && err.errors?.terms) {
        setTermsFor({ period, queue: queue.slice(claimed) });
      } else {
        notify.stickyError("Could not claim the reward", err instanceof ApiError ? err.message : "Please try again.");
        setTermsFor(null);
      }
    } finally {
      setBusy(null);
      setReload((n) => n + 1);
    }
    if (claimed > 0 && balance !== null) {
      notify.success(
        claimed === 1 ? `${formatRm(lastAmount)} added to your Purchase Balance` : `${claimed} rewards added to your Purchase Balance`,
        `${adjusted ? "A reward was adjusted to the monthly limit. " : ""}Purchase Balance is now ${formatRm(balance)}.`,
      );
    }
  }

  const currentPeriod = currentKlPeriod();
  const canClaim = data?.eligible ?? false;

  return (
    <div>
      <PageHeader title="Rebate" subtitle="Reach a monthly purchase target and claim its reward into your Purchase Balance." />
      {error && <ErrorNote message={error} />}

      {data && !data.eligible && (
        <p className="mb-4 rounded-lg border border-warning-ink/30 bg-warning-surface px-4 py-3 text-sm text-warning-ink">
          Your account is not part of the rebate programme, so rewards cannot be claimed. Contact PekanGame if you think this is a mistake.
        </p>
      )}
      {data?.upcoming_ladder && (
        <div className="mb-4">
          <UpcomingLadderNotice upcoming={data.upcoming_ladder} />
        </div>
      )}

      <div className="mb-4 grid items-start gap-4 xl:grid-cols-2">
        {data ? (
          <BalanceHero
            icon="gift"
            label="Unspent rebate"
            amount={formatRm(data.unspent_rebate_sen)}
            note="Rewards you claimed and have not spent yet. It is part of your Purchase Balance, and orders spend it first."
            actions={
              <Link href="/balance#history" className={buttonClass("secondary")}>
                Balance history
              </Link>
            }
          />
        ) : (
          <Skeleton className="h-[200px]" />
        )}
        <StepsCard
          title="How the rebate works"
          steps={[
            { title: "Buy as usual", body: "Every order delivered this month counts toward your target. The count starts again on the 1st." },
            { title: "Reach a target, claim its reward", body: "Each reward can be claimed as soon as you reach it. Claim them in order; it goes into your Purchase Balance." },
            { title: "Claim by the end of next month", body: "A reward not claimed by the last day of the following month lapses." },
          ]}
        />
      </div>

      {!data && !error && <Skeleton className="mb-4 h-[360px]" />}
      {data && data.months.length === 0 && (
        <Panel className="mb-4">
          <CardHead title="This month" />
          <EmptyState
            title="No rebate table for this month"
            text={
              data.upcoming_ladder
                ? "Your targets and rewards appear here once the new table takes effect."
                : "When PekanGame runs a rebate table for your pricing tier, your monthly targets and rewards appear here."
            }
          />
        </Panel>
      )}
      {data && data.months.length > 0 && (
        <div className="mb-4 grid items-start gap-4 xl:grid-cols-2">
          {data.months.map((month) => (
            <RebateMonthPanel
              key={month.period}
              month={month}
              isCurrent={month.period === currentPeriod}
              canClaim={canClaim}
              busy={busy === month.period}
              onClaim={startClaim}
            />
          ))}
        </div>
      )}

      {data && (
        <div className="grid items-start gap-4 xl:grid-cols-2">
          <Panel>
            <CardHead title="Reward history" description="Rewards you claimed or let lapse, newest first." divided />
            {data.history.length === 0 ? (
              <p className="p-6 text-center text-sm text-ink-subtle">No claimed or lapsed rewards yet.</p>
            ) : (
              <ul className="divide-y divide-border">
                {data.history.map((row) => {
                  const when = dateAndTime(row.state === "claimed" ? row.claimed_at : row.expired_at);
                  return (
                    <li key={`${row.period}-${row.kind}-${row.step}`} className="flex items-start justify-between gap-3 px-5 py-3">
                      <span className="flex min-w-0 flex-col">
                        <span className="text-[13px] font-semibold text-ink">
                          {rebateEntryLabel(row)}
                        </span>
                        <span className="text-xs text-ink-subtle">
                          {row.state === "claimed" ? "Claimed" : "Lapsed"} {when.date}
                          {row.adjusted ? " · adjusted to the monthly limit" : ""}
                        </span>
                      </span>
                      <span className="flex shrink-0 flex-col items-end gap-1">
                        <span className={`text-[13px] font-semibold ${row.state === "claimed" ? "text-success-ink" : "text-ink-subtle line-through"}`}>{formatRm(row.amount_sen)}</span>
                        <Badge tone={row.state === "claimed" ? "success" : "neutral"}>{row.state === "claimed" ? "Claimed" : "Lapsed"}</Badge>
                      </span>
                    </li>
                  );
                })}
              </ul>
            )}
          </Panel>
          <Panel>
            <CardHead
              title="Programme terms"
              description={data.terms.accepted ? `Version ${data.terms.version}, accepted` : `Version ${data.terms.version}. You accept these on your first claim.`}
              divided
            />
            <div className="px-5 py-4">
              <p className="mb-3 text-[13px] font-semibold text-ink">{data.terms.title}</p>
              <TermsText terms={data.terms} />
            </div>
          </Panel>
        </div>
      )}

      {data && (
        <TermsDialog
          terms={data.terms}
          open={termsFor !== null}
          busy={busy !== null}
          onAccept={() => termsFor && void runClaims(termsFor, true)}
          onClose={() => setTermsFor(null)}
        />
      )}
    </div>
  );
}
