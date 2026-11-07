"use client";

/**
 * ADR-118 PR 4: the Campaigns page's three forms (new campaign, new fee row,
 * promo code), in the CS Knowledge dialog pattern. Money is typed in RM and
 * sent as sen; every rule is enforced again by the backend FormRequests.
 */

import React, { useEffect, useState } from "react";
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogHeaderActions,
  DialogClose,
  DialogTitle,
  DialogContent,
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { ApiError } from "@/lib/api-client";
import { klInputToIso, isoToKlInput } from "@/lib/date-range";
import { marginCheck, rm, toSen, type CodeValues, type PromoCodeRow, type RejectedPackage } from "@/lib/campaigns";

export function FormDialog({ open, title, onClose, children }: { open: boolean; title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <Dialog open={open} onOpenChange={(e) => { if (!e.value) onClose(); }}>
      <DialogPortal>
        <DialogBackdrop />
        <DialogPositioner>
          <DialogPopup className="w-full max-w-lg">
            <DialogHeader>
              <DialogTitle>{title}</DialogTitle>
              <DialogHeaderActions>
                <DialogClose aria-label="Close">
                  <CloseIcon className="h-5 w-5" />
                </DialogClose>
              </DialogHeaderActions>
            </DialogHeader>
            <DialogContent>{open && children}</DialogContent>
          </DialogPopup>
        </DialogPositioner>
      </DialogPortal>
    </Dialog>
  );
}

/** Submit + error handling shared by the forms; a 422 shows its first field message. */
function useSubmit(onClose: () => void) {
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function run(fn: () => Promise<unknown>) {
    setError(null);
    setBusy(true);
    try {
      await fn();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setBusy(false);
    }
  }

  return { error, busy, run };
}

function Actions({ busy, onClose, label }: { busy: boolean; onClose: () => void; label: string }) {
  return (
    <div className="flex items-center justify-end gap-3 pt-2">
      <Button type="button" variant="outlined" onClick={onClose} disabled={busy}>
        Cancel
      </Button>
      <Button type="submit" disabled={busy}>
        {busy ? "Saving…" : label}
      </Button>
    </div>
  );
}

function ErrorLine({ error }: { error: string | null }) {
  return error ? <p className="rounded-lg bg-danger-surface px-3 py-2 text-sm text-danger-ink">{error}</p> : null;
}

const thisMonth = () => new Date().toLocaleDateString("en-CA", { timeZone: "Asia/Kuala_Lumpur" }).slice(0, 7);

/** V12: a fee row (and, for a new campaign, its name). A package deal is its monthly fee with an end month. */
export function FeeForm({
  withName,
  submitLabel,
  onClose,
  onSubmit,
}: {
  withName: boolean;
  submitLabel: string;
  onClose: () => void;
  onSubmit: (v: { name: string; monthly_fee_sen: number; from_month: string; to_month: string | null }) => Promise<unknown>;
}) {
  const [name, setName] = useState("");
  const [fee, setFee] = useState("");
  const [from, setFrom] = useState(thisMonth());
  const [to, setTo] = useState("");
  const { error, busy, run } = useSubmit(onClose);

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        void run(() => onSubmit({ name: name.trim(), monthly_fee_sen: toSen(fee) ?? -1, from_month: from, to_month: to || null }));
      }}
    >
      <ErrorLine error={error} />
      {withName && (
        <div>
          <Label htmlFor="campaign-name">Campaign name</Label>
          <Input id="campaign-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={120} placeholder="e.g. KOL Aiman (TikTok)" required />
        </div>
      )}
      <div>
        <Label htmlFor="campaign-fee">Monthly fee (RM)</Label>
        <Input id="campaign-fee" inputMode="decimal" value={fee} onChange={(e) => setFee(e.target.value)} placeholder="500.00" required />
        <p className="mt-1 text-theme-xs text-ink-muted">Include SST if the KOL charges it. RM0 is allowed. A package (&quot;RM1,500 for 3 months&quot;) is its monthly fee with an end month.</p>
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <Label htmlFor="campaign-from">First month</Label>
          <Input id="campaign-from" type="month" value={from} onChange={(e) => setFrom(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="campaign-to">Last month (optional)</Label>
          <Input id="campaign-to" type="month" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
      </div>
      <p className="text-theme-xs text-ink-muted">The fee is an advertising expense in each month it covers. A closed month can never change.</p>
      <Actions busy={busy} onClose={onClose} label={submitLabel} />
    </form>
  );
}

/**
 * V3/V10/V11/V17: a promo code. The string is chosen once; the percentage is
 * locked after the first redemption; budget, cap, window and on/off stay
 * editable. The packages the margin floor would refuse are listed live.
 */
export function CodeForm({
  existing,
  onClose,
  onSubmit,
}: {
  existing: PromoCodeRow | null;
  onClose: () => void;
  onSubmit: (v: CodeValues) => Promise<unknown>;
}) {
  const [code, setCode] = useState(existing?.code ?? "");
  const [percent, setPercent] = useState(existing ? String(existing.discount_percent) : "5");
  const [max, setMax] = useState(existing?.max_discount_sen ? (existing.max_discount_sen / 100).toFixed(2) : "");
  const [budget, setBudget] = useState(existing ? (existing.budget_sen / 100).toFixed(2) : "");
  const [startsAt, setStartsAt] = useState(isoToKlInput(existing?.starts_at ?? null));
  const [endsAt, setEndsAt] = useState(isoToKlInput(existing?.ends_at ?? null));
  const [active, setActive] = useState(existing?.is_active ?? true);
  const [rejected, setRejected] = useState<RejectedPackage[] | null>(null);
  const { error, busy, run } = useSubmit(onClose);
  const percentLocked = existing?.has_redemptions ?? false;

  useEffect(() => {
    const p = Number(percent);
    if (!(p > 0)) return;
    const t = setTimeout(() => {
      marginCheck(p, toSen(max)).then((r) => setRejected(r.rejected), () => setRejected(null));
    }, 300);
    return () => clearTimeout(t);
  }, [percent, max]);

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        void run(() =>
          onSubmit({
            ...(existing ? {} : { code: code.trim().toUpperCase() }),
            ...(percentLocked ? {} : { discount_percent: Number(percent) }),
            max_discount_sen: toSen(max),
            budget_sen: toSen(budget) ?? 0,
            is_active: active,
            starts_at: klInputToIso(startsAt),
            ends_at: klInputToIso(endsAt),
          }),
        );
      }}
    >
      <ErrorLine error={error} />
      <div>
        <Label htmlFor="code-code">Code</Label>
        <Input
          id="code-code"
          value={code}
          onChange={(e) => setCode(e.target.value.toUpperCase())}
          disabled={existing !== null}
          maxLength={20}
          placeholder="AIMAN5"
          required
          className="font-mono uppercase"
        />
        <p className="mt-1 text-theme-xs text-ink-muted">3 to 20 letters and digits. Never reused, even after it ends, because old videos keep circulating.</p>
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <Label htmlFor="code-percent">Discount (%)</Label>
          <Input id="code-percent" inputMode="decimal" value={percent} onChange={(e) => setPercent(e.target.value)} disabled={percentLocked} required />
          <p className="mt-1 text-theme-xs text-ink-muted">
            {percentLocked ? "Fixed: this code has been redeemed. A new offer is a new code." : "Customers see the RM saved, never the %."}
          </p>
        </div>
        <div>
          <Label htmlFor="code-max">Max per order (RM, optional)</Label>
          <Input id="code-max" inputMode="decimal" value={max} onChange={(e) => setMax(e.target.value)} placeholder="No cap" />
        </div>
      </div>
      <div>
        <Label htmlFor="code-budget">Budget (RM)</Label>
        <Input id="code-budget" inputMode="decimal" value={budget} onChange={(e) => setBudget(e.target.value)} placeholder="300.00" required />
        <p className="mt-1 text-theme-xs text-ink-muted">The total discount this code may give. Lowering it below what is already used only stops new redemptions.</p>
      </div>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <Label htmlFor="code-starts">Starts (optional)</Label>
          <Input id="code-starts" type="datetime-local" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} />
        </div>
        <div>
          <Label htmlFor="code-ends">Ends (optional)</Label>
          <Input id="code-ends" type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} />
        </div>
      </div>
      <div className="flex items-center gap-3">
        <Switch checked={active} onChange={setActive} ariaLabel="Active" />
        <span className="text-theme-sm text-ink">{active ? "Active" : "Paused"}</span>
      </div>
      {rejected !== null && (
        <div className="rounded-lg border border-border bg-subtle p-3 text-theme-xs">
          {rejected.length === 0 ? (
            <p className="text-ink-muted">Every package keeps at least the minimum margin at this discount.</p>
          ) : (
            <>
              <p className="font-medium text-ink">Refused on {rejected.length} package{rejected.length === 1 ? "" : "s"} (below the minimum margin):</p>
              <ul className="mt-1 max-h-32 space-y-0.5 overflow-y-auto text-ink-muted">
                {rejected.map((p) => (
                  <li key={p.package_id}>
                    {p.game_name} · {p.package_name} ({rm(p.price_sen)})
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}
      <Actions busy={busy} onClose={onClose} label={existing ? "Save code" : "Create code"} />
    </form>
  );
}

/** V12: the one change a fee row allows — its last month (empty keeps it open). */
/** ADR-118 V24: ending stops the codes and, with `month`, the fee. A fee with a last month keeps it. */
export function EndCampaignForm({ month, onClose, onSubmit }: { month: string | null; onClose: () => void; onSubmit: (lastFeeMonth?: string) => Promise<void> }) {
  const [last, setLast] = useState(month ?? "");
  const { error, busy, run } = useSubmit(onClose);

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        void run(() => onSubmit(month === null ? undefined : last));
      }}
    >
      <ErrorLine error={error} />
      <p className="text-theme-sm text-ink-muted">Its codes stop working at once. Customers it brought in keep counting for it.</p>
      {month !== null && (
        <div>
          <Label htmlFor="last-fee-month">Last month paid</Label>
          <Input id="last-fee-month" type="month" required value={last} onChange={(e) => setLast(e.target.value)} />
          <p className="mt-1 text-theme-xs text-ink-muted">The fee stops after this month. A month already closed can&apos;t change.</p>
        </div>
      )}
      <Actions busy={busy} onClose={onClose} label="End campaign" />
    </form>
  );
}

export function EndMonthForm({ current, onClose, onSubmit }: { current: string | null; onClose: () => void; onSubmit: (toMonth: string | null) => Promise<unknown> }) {
  const [to, setTo] = useState(current ?? "");
  const { error, busy, run } = useSubmit(onClose);

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        void run(() => onSubmit(to || null));
      }}
    >
      <ErrorLine error={error} />
      <div>
        <Label htmlFor="fee-to">Last month</Label>
        <Input id="fee-to" type="month" value={to} onChange={(e) => setTo(e.target.value)} />
        <p className="mt-1 text-theme-xs text-ink-muted">Leave empty to keep the fee running. A month already closed in Accounting cannot change.</p>
      </div>
      <Actions busy={busy} onClose={onClose} label="Save" />
    </form>
  );
}
