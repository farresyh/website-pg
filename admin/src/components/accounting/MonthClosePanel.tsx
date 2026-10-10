"use client";

/**
 * ADR-083 2026-10-10 addendum, decisions 9–14 — the month close. It
 * allocates the month's operating profit (plus any drift carried in from an
 * earlier closed month) across envelopes, exactly; snapshots the cash
 * accounts; and shows the "where is the money" equation. The backend
 * re-checks every rule and its 422 message is shown as-is.
 */

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { ApiError } from "@/lib/api-client";
import { formatRm } from "@/lib/budget-envelopes";
import { closeMonth, createCashAccount, reopenMonthClose, updateCashAccount, type MonthClose } from "@/lib/accounting-summary";
import { CashEquationTable, cashGapSen } from "@/components/accounting/CashEquationTable";

/** "12.34" or "-12.34" → sen; NaN otherwise. A loss month allocates negatively. */
function signedRmToSen(rm: string): number {
  return /^-?\d+(\.\d{1,2})?$/.test(rm.trim()) ? Math.round(parseFloat(rm) * 100) : NaN;
}

const sumSen = (values: Record<number, string>) =>
  Object.values(values).reduce((sum, v) => sum + (Number.isFinite(signedRmToSen(v)) ? signedRmToSen(v) : 0), 0);

interface Props {
  token: string;
  year: number;
  month: number;
  data: MonthClose;
  onChanged: () => Promise<void> | void;
}

export function MonthClosePanel({ token, year, month, data, onChanged }: Props) {
  const [allocations, setAllocations] = useState<Record<number, string>>({});
  const [balances, setBalances] = useState<Record<number, string>>({});
  const [gapNote, setGapNote] = useState("");
  const [newAccount, setNewAccount] = useState("");
  const [reopenReason, setReopenReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function run(action: () => Promise<unknown>) {
    setError(null);
    setBusy(true);
    try {
      await action();
      await onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setBusy(false);
    }
  }

  const close = data.close;
  const allocatedSen = sumSen(allocations);
  const cashSen = close ? close.cash_balances.reduce((s, b) => s + b.balance_sen, 0) : sumSen(balances);
  const gap = cashGapSen(data.position, cashSen);

  function handleClose(e: React.FormEvent) {
    e.preventDefault();
    const lines = Object.entries(allocations)
      .filter(([, rm]) => rm.trim() !== "")
      .map(([id, rm]) => ({ budget_envelope_id: Number(id), amount_sen: signedRmToSen(rm) }));
    const cash = data.cash_accounts.map((a) => ({ cash_account_id: a.id, balance_sen: signedRmToSen(balances[a.id] ?? "") }));

    if (lines.some((l) => !Number.isFinite(l.amount_sen)) || cash.some((c) => !Number.isFinite(c.balance_sen))) {
      setError("Enter every amount as RM, e.g. 120.50 (a loss allocation can be negative).");
      return;
    }
    void run(() => closeMonth(token, { year, month, lines, cash_balances: cash, gap_note: gapNote.trim() || null }));
  }

  return (
    <section className="mt-8 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">Month close — {data.label}</h2>
        {close && (
          <span className="text-theme-xs text-gray-500 dark:text-gray-400">
            Closed {new Date(close.closed_at).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric" })}
            {close.closed_by ? ` by ${close.closed_by}` : ""}
          </span>
        )}
      </div>

      {error && <p className="mb-4 rounded-lg bg-danger-surface px-3 py-2 text-sm text-danger-ink">{error}</p>}

      <div className="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Figure label="Operating profit" sen={close?.operating_profit_sen ?? data.operating_profit_sen} hint="What the close allocates." />
        <Figure
          label="Manual expenses (Envelope)"
          sen={data.envelope_expenses.reduce((s, e) => s + e.amount_sen, 0)}
          hint={data.envelope_expenses.map((e) => `${e.label} ${formatRm(e.amount_sen)}`).join(" · ") || "None this month."}
        />
        <Figure label="Net (information only)" sen={data.net_sen} hint="Operating profit less manual expenses." />
      </div>

      {data.prior_adjustment_sen !== 0 && (
        <p className="mb-3 text-theme-sm text-gray-600 dark:text-gray-300">
          Prior-month adjustment: <span className="font-mono">{formatRm(data.prior_adjustment_sen)}</span> — an earlier closed month&apos;s figure has moved since its close.
        </p>
      )}
      {close && close.drift_sen !== 0 && (
        <p className="mb-3 rounded-lg bg-warning-surface px-3 py-2 text-theme-sm text-warning-ink">
          This month&apos;s live figure has moved by {formatRm(close.drift_sen)} since it was closed. The next close carries it as a prior-month adjustment.
        </p>
      )}

      {close ? (
        <>
          <p className="mb-4 text-theme-sm text-gray-600 dark:text-gray-300">
            Allocated <span className="font-mono font-medium">{formatRm(data.allocate_sen)}</span>. Cash accounts:{" "}
            {close.cash_balances.map((b) => `${b.name} ${formatRm(b.balance_sen)}`).join(" · ")}.
          </p>
          <CashEquationTable position={data.position} cashAccountsSen={cashSen} />
          {close.gap_note && <p className="mt-3 text-theme-sm text-gray-600 dark:text-gray-300">Gap note: {close.gap_note}</p>}
          {close.can_reopen && (
            <form
              onSubmit={(e) => { e.preventDefault(); void run(() => reopenMonthClose(token, close.id, reopenReason.trim())); }}
              className="mt-5 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800"
            >
              <div className="min-w-64 flex-1">
                <Label htmlFor="reopen_reason">Reopen this month — reason</Label>
                <Input id="reopen_reason" value={reopenReason} onChange={(e) => setReopenReason(e.target.value)} placeholder="A refund landed after the close" required />
              </div>
              <Button type="submit" size="small" variant="outlined" severity="danger" disabled={busy}>Reopen</Button>
            </form>
          )}
        </>
      ) : data.blocked_reason ? (
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">{data.blocked_reason}</p>
      ) : (
        <form onSubmit={handleClose} className="space-y-5">
          <div>
            <p className="mb-2 text-theme-sm text-gray-600 dark:text-gray-300">
              Allocate exactly <span className="font-mono font-medium">{formatRm(data.allocate_sen)}</span> across envelopes. Allocated so far:{" "}
              <span className={`font-mono font-medium ${allocatedSen === data.allocate_sen ? "text-success-ink" : "text-warning-ink"}`}>
                {formatRm(allocatedSen)}
              </span>
            </p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {data.envelopes.map((env) => (
                <div key={env.id}>
                  <Label htmlFor={`alloc_${env.id}`}>{env.name} (RM)</Label>
                  <Input id={`alloc_${env.id}`} value={allocations[env.id] ?? ""} onChange={(e) => setAllocations((c) => ({ ...c, [env.id]: e.target.value }))} placeholder="0.00" />
                </div>
              ))}
            </div>
          </div>

          <div>
            <p className="mb-2 text-theme-sm text-gray-600 dark:text-gray-300">Company money in each account on the last day of {data.label} (RM)</p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {data.cash_accounts.map((acc) => (
                <div key={acc.id}>
                  <div className="flex items-baseline justify-between">
                    <Label htmlFor={`cash_${acc.id}`}>{acc.name}</Label>
                    <button type="button" onClick={() => void run(() => updateCashAccount(token, acc.id, { is_active: false }))} className="text-theme-xs text-gray-400 hover:underline">
                      Archive
                    </button>
                  </div>
                  <Input id={`cash_${acc.id}`} value={balances[acc.id] ?? ""} onChange={(e) => setBalances((c) => ({ ...c, [acc.id]: e.target.value }))} placeholder="0.00" required />
                </div>
              ))}
              <div className="flex items-end gap-2">
                <div className="flex-1">
                  <Label htmlFor="new_cash_account">Add a cash account</Label>
                  <Input id="new_cash_account" value={newAccount} onChange={(e) => setNewAccount(e.target.value)} placeholder="LWF Maybank" />
                </div>
                <Button
                  type="button"
                  size="small"
                  variant="outlined"
                  disabled={busy || !newAccount.trim()}
                  onClick={() => void run(async () => { await createCashAccount(token, newAccount.trim()); setNewAccount(""); })}
                >
                  Add
                </Button>
              </div>
            </div>
          </div>

          <CashEquationTable position={data.position} cashAccountsSen={cashSen} />

          <div>
            <Label htmlFor="gap_note">Gap note {Math.abs(gap) > 100 ? "(required — the gap is above RM1)" : "(optional)"}</Label>
            <textarea
              id="gap_note"
              value={gapNote}
              onChange={(e) => setGapNote(e.target.value)}
              rows={2}
              placeholder="e.g. RM20 transfer to Digiflazz left the bank on 30 Sep, credited 1 Oct"
              className="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-theme-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
            />
          </div>

          <div className="flex justify-end">
            <Button type="submit" size="small" disabled={busy}>{busy ? "Closing…" : `Close ${data.label}`}</Button>
          </div>
        </form>
      )}
    </section>
  );
}

function Figure({ label, sen, hint }: { label: string; sen: number; hint: string }) {
  return (
    <div className="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.02]">
      <p className="text-theme-xs text-gray-500 dark:text-gray-400">{label}</p>
      <p className={`mt-1 font-mono text-lg font-semibold ${sen < 0 ? "text-danger-ink" : "text-gray-800 dark:text-white/90"}`}>{formatRm(sen)}</p>
      <p className="mt-1 text-theme-xs text-gray-400">{hint}</p>
    </div>
  );
}
