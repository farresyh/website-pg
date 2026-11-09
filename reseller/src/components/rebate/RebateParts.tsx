"use client";

import { useState } from "react";
import Link from "next/link";
import { formatRm } from "@/lib/format";
import { claimByDate, claimQueue, currentKlPeriod, formatRebateDay, formatRebatePeriod, type RebateMonth, type RebatePage } from "@/lib/rebate";
import { Badge, CardHead, Panel, buttonClass } from "@/components/composites";
import { Button } from "@/components/ui/button";
import { PortalIcon } from "@/components/shell/PortalIcon";
import {
  Dialog,
  DialogBackdrop,
  DialogClose,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogHeaderActions,
  DialogPopup,
  DialogPortal,
  DialogPositioner,
  DialogTitle,
} from "@/components/ui/dialog";

/**
 * ADR-139 decision 41: the pieces of the Rebate page and the dashboard card.
 * Every figure is the server's (`RebatePortalView`); the bar's width is the
 * only arithmetic here, and it is display only.
 */

/** Progress toward the next target. Width only; the words carry the meaning. */
export function ProgressBar({ value, max }: { value: number; max: number }) {
  const percent = max > 0 ? Math.min(100, Math.max(0, (value / max) * 100)) : 100;
  return (
    <div role="progressbar" aria-valuemin={0} aria-valuemax={max} aria-valuenow={Math.min(value, max)} className="h-2 w-full overflow-hidden rounded-full bg-subtle">
      <div className="h-full rounded-full bg-cyan-600" style={{ width: `${percent}%` }} />
    </div>
  );
}

/** "RM 120.00 more to reach RM 500.00", or that the top reward is reached. */
export function progressLine(month: RebateMonth): string {
  if (month.finalised) return "This month has ended. Rewards reached are shown below.";
  if (month.next_threshold_sen === null || month.remaining_sen === null) return "You have reached the top reward this month.";
  return `${formatRm(month.remaining_sen)} more to reach ${formatRm(month.next_threshold_sen)}`;
}

export function monthLabel(month: RebateMonth, isCurrent: boolean): string {
  return `${isCurrent ? "This month" : month.finalised ? "Last month, final" : "Last month"} · claim by ${claimByDate(month.claim_deadline)}`;
}

/**
 * One month's ladder: each target, its reward and its state. Claims go in
 * order (decision 11), so only the first claimable step has a Claim button;
 * "Claim all" walks them in order. The month's adjustment (step 0) is
 * claimable on its own.
 */
export function RebateMonthPanel({
  month,
  isCurrent,
  canClaim,
  busy,
  onClaim,
}: {
  month: RebateMonth;
  isCurrent: boolean;
  canClaim: boolean;
  busy: boolean;
  onClaim: (period: string, queue: number[]) => void;
}) {
  const queue = claimQueue(month);
  const firstStep = queue.find((s) => s !== 0);
  const top = month.steps.at(-1)?.threshold_sen ?? 0;

  return (
    <Panel>
      <CardHead
        title={formatRebatePeriod(month.period)}
        description={monthLabel(month, isCurrent)}
        divided
        action={
          canClaim && queue.length > 1 ? (
            <Button size="small" disabled={busy} onClick={() => onClaim(month.period, queue)}>
              {busy ? "Claiming…" : `Claim all (${queue.length})`}
            </Button>
          ) : undefined
        }
      />
      <div className="px-5 py-4">
        <p className="text-xs text-ink-subtle">Purchases counted</p>
        <p className="mt-0.5 text-2xl font-[650] leading-8 tracking-[-0.02em] text-ink">{formatRm(month.sales_sen)}</p>
        <div className="mt-3">
          <ProgressBar value={month.sales_sen} max={month.next_threshold_sen ?? top} />
        </div>
        <p className="mt-2 text-[13px] text-ink-muted">{progressLine(month)}</p>
      </div>
      <ul className="divide-y divide-border border-t border-border">
        {month.steps.map((s) => (
          <li key={s.step} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3">
            <span className="flex min-w-0 items-center gap-3">
              <span
                className={`grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-bold ${
                  s.state === "locked" ? "bg-subtle text-ink-subtle" : "bg-cyan-600 text-on-cyan"
                }`}
              >
                {s.state === "claimed" ? <PortalIcon name="check" className="h-3.5 w-3.5" /> : s.step}
              </span>
              <span className="flex min-w-0 flex-col">
                <span className="text-[13px] font-semibold text-ink">Reach {formatRm(s.threshold_sen)}</span>
                <span className="text-xs text-ink-subtle">
                  {s.state === "claimed" && s.paid_sen !== null ? `Paid ${formatRm(s.paid_sen)}` : `Reward ${formatRm(s.reward_sen)}`}
                </span>
                {s.adjusted && <span className="text-xs text-warning-ink">Reward adjusted to the monthly limit</span>}
              </span>
            </span>
            <StepAction
              state={s.state}
              claimable={canClaim && s.step === firstStep}
              busy={busy}
              onClaim={() => onClaim(month.period, [s.step])}
            />
          </li>
        ))}
        {month.adjustment && (
          <li className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 bg-subtle/60 px-5 py-3">
            <span className="flex min-w-0 flex-col">
              <span className="text-[13px] font-semibold text-ink">Monthly adjustment</span>
              <span className="text-xs text-ink-subtle">
                {formatRm(month.adjustment.amount_sen)} owed to you after the month&apos;s final count
              </span>
            </span>
            <StepAction state={month.adjustment.state} claimable={canClaim} busy={busy} onClaim={() => onClaim(month.period, [0])} />
          </li>
        )}
      </ul>
    </Panel>
  );
}

function StepAction({ state, claimable, busy, onClaim }: { state: string; claimable: boolean; busy: boolean; onClaim: () => void }) {
  if (state === "claimable") {
    return claimable ? (
      <Button size="small" disabled={busy} onClick={onClaim}>
        Claim
      </Button>
    ) : (
      <Badge tone="info">Ready to claim</Badge>
    );
  }
  if (state === "claimed") return <Badge tone="success">Claimed</Badge>;
  if (state === "expired") return <Badge tone="neutral">Lapsed</Badge>;
  return <span className="text-xs text-ink-subtle">Not reached yet</span>;
}

/** Terms addendum, decision 2: a new table is announced before it takes effect, own tier only. */
export function UpcomingLadderNotice({ upcoming }: { upcoming: NonNullable<RebatePage["upcoming_ladder"]> }) {
  return (
    <section className="rounded-lg border border-info-ink/25 bg-info-surface px-5 py-4">
      <p className="flex items-center gap-2 text-sm font-semibold text-info-ink">
        <PortalIcon name="info" className="h-4 w-4" />
        A new rebate table takes effect on {formatRebateDay(upcoming.effective_from)}
      </p>
      {upcoming.steps.length === 0 ? (
        <p className="mt-1 text-[13px] text-info-ink">Your pricing tier has no rewards in the new table.</p>
      ) : (
        <ul className="mt-2 grid gap-x-6 gap-y-1 text-[13px] text-info-ink sm:grid-cols-2">
          {upcoming.steps.map((s) => (
            <li key={s.step}>
              Reach {formatRm(s.threshold_sen)} → reward {formatRm(s.reward_sen)}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/** The programme terms as the server sends them (Malay, verbatim, ADR-139 terms addendum). */
export function TermsText({ terms }: { terms: RebatePage["terms"] }) {
  return (
    <ol className="list-decimal space-y-2 pl-5 text-[13px] leading-5 text-ink-muted">
      {terms.clauses.map((clause) => (
        <li key={clause}>{clause}</li>
      ))}
    </ol>
  );
}

/** Asked once, on the first claim under a terms version: the claim carries the tick. */
export function TermsDialog({
  terms,
  open,
  busy,
  onAccept,
  onClose,
}: {
  terms: RebatePage["terms"];
  open: boolean;
  busy: boolean;
  onAccept: () => void;
  onClose: () => void;
}) {
  const [ticked, setTicked] = useState(false);
  return (
    <Dialog
      open={open}
      onOpenChange={(e) => {
        if (!e.value) {
          setTicked(false);
          onClose();
        }
      }}
    >
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="max-w-xl">
            <DialogHeader>
              <DialogTitle>{terms.title}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <PortalIcon name="close" className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent className="max-h-[60vh] overflow-y-auto">
              <p className="mb-3 text-[13px] text-ink">Read and accept the programme terms before your first claim.</p>
              <TermsText terms={terms} />
              <label className="mt-4 flex items-start gap-2.5 text-[13px] font-medium text-ink">
                <input type="checkbox" className="mt-0.5 h-4 w-4 accent-cyan-600" checked={ticked} onChange={(e) => setTicked(e.target.checked)} />
                I have read and accept these terms.
              </label>
            </DialogContent>
            <DialogFooter>
              <Button variant="outlined" severity="secondary" onClick={onClose}>
                Cancel
              </Button>
              <Button disabled={!ticked || busy} onClick={onAccept}>
                {busy ? "Claiming…" : "Accept and claim"}
              </Button>
            </DialogFooter>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}

/**
 * Decision 41: the dashboard's rebate card, "RM X more to the next reward",
 * plus how many rewards wait to be claimed. It also carries the new-table
 * notice (terms addendum, decision 2), so a reseller sees it on sign-in.
 * Nothing shows for an account outside the programme or with no table.
 */
export function RebateProgressCard({ data }: { data: RebatePage }) {
  if (!data.eligible) return null;
  const current = data.months.find((m) => m.period === currentKlPeriod()) ?? null;
  const ready = data.months.reduce((n, m) => n + claimQueue(m).length, 0);
  if (!current && ready === 0 && !data.upcoming_ladder) return null;

  return (
    <Panel>
      <CardHead
        title="Rebate"
        description={current ? monthLabel(current, true) : "Monthly purchase rewards"}
        action={
          <Link href="/rebate" className={buttonClass(ready > 0 ? "primary" : "secondary", true)}>
            {ready > 0 ? `Claim ${ready} reward${ready === 1 ? "" : "s"}` : "Open Rebate"}
          </Link>
        }
      />
      {current && (
        <div className="px-5 pb-4">
          <p className="text-2xl font-[650] leading-8 tracking-[-0.02em] text-ink">{formatRm(current.sales_sen)}</p>
          <p className="mb-3 text-xs text-ink-subtle">Purchases counted this month</p>
          <ProgressBar value={current.sales_sen} max={current.next_threshold_sen ?? current.steps.at(-1)?.threshold_sen ?? 0} />
          <p className="mt-2 text-[13px] font-medium text-ink">{progressLine(current)}</p>
        </div>
      )}
      {!current && ready > 0 && <p className="px-5 pb-4 text-[13px] text-ink-muted">Last month&apos;s rewards are waiting. Claim them before they lapse.</p>}
      {data.upcoming_ladder && (
        <div className="px-5 pb-5">
          <UpcomingLadderNotice upcoming={data.upcoming_ladder} />
        </div>
      )}
    </Panel>
  );
}
