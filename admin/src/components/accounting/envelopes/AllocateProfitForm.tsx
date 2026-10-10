"use client";

/**
 * The manual "Allocate Monthly Profit" form, unchanged in behaviour — it
 * now writes one posting across the chosen envelopes. The month close
 * replaces it (ADR-083 2026-10-10 addendum, decision 9, PR-2).
 */

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { ApiError } from "@/lib/api-client";
import { allocateMonthlyProfit, formatRm, rmToSen, type BudgetEnvelopeIndex } from "@/lib/budget-envelopes";

const SUMMARY_LINE_LABELS: Record<string, string> = {
  sales_revenue_sen: "Sales revenue",
  membership_revenue_sen: "Membership revenue",
  cogs_sen: "COGS",
  payment_processing_gain_loss_sen: "Payment processing net gain/(loss)",
  supplier_prepaid_topup_sen: "Supplier prepaid — top-up",
  bank_transfer_fees_sen: "Bank / transfer fees",
  supplier_prepaid_fx_variance_sen: "Supplier prepaid — FX variance true-up",
  affiliate_commission_expense_sen: "Affiliate commission expense",
  affiliate_tier_fees_sen: "Affiliate tier fees (from earnings, no cash)",
  voucher_liability_issued_sen: "Voucher liability issued",
  reseller_wallet_balance_sen: "Reseller wallet balance (current, not month-end)",
};

interface Props {
  token: string;
  index: BudgetEnvelopeIndex;
  onAllocated: () => Promise<void> | void;
}

export function AllocateProfitForm({ token, index, onAllocated }: Props) {
  const [amounts, setAmounts] = useState<Record<number, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const totalSen = Object.values(amounts).reduce((sum, v) => sum + (Number.isFinite(rmToSen(v)) ? rmToSen(v) : 0), 0);
  const overEstimate = totalSen > index.current_month_rough_pl_estimate_sen;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    const allocations = Object.entries(amounts)
      .map(([id, rm]) => ({ budget_envelope_id: Number(id), amount_sen: rmToSen(rm) }))
      .filter((a) => Number.isFinite(a.amount_sen) && a.amount_sen > 0);

    if (allocations.length === 0) {
      setError("Enter at least one envelope's allocation amount.");
      return;
    }

    setSubmitting(true);
    try {
      await allocateMonthlyProfit(token, { period_label: index.current_month_label, allocations });
      setAmounts({});
      await onAllocated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not allocate profit.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="mb-6 space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}
      <p className="text-theme-xs font-medium text-gray-600 dark:text-gray-400">
        {index.current_month_label} — reference figures from the Monthly Summary (not a single derived &quot;net profit&quot; — you decide the split).
      </p>
      <div className="grid grid-cols-2 gap-x-6 gap-y-1 text-theme-xs sm:grid-cols-4">
        {Object.entries(index.current_month_summary).map(([key, value]) => (
          <div key={key}>
            <span className="text-gray-400">{SUMMARY_LINE_LABELS[key] ?? key}</span>
            <p className="font-mono text-gray-700 dark:text-gray-300">{formatRm(value)}</p>
          </div>
        ))}
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {index.envelopes.filter((e) => e.is_active).map((envelope) => (
          <div key={envelope.id}>
            <Label htmlFor={`alloc_${envelope.id}`}>{envelope.name} (RM)</Label>
            <Input
              id={`alloc_${envelope.id}`}
              value={amounts[envelope.id] ?? ""}
              onChange={(e) => setAmounts((cur) => ({ ...cur, [envelope.id]: e.target.value }))}
              placeholder="0.00"
            />
          </div>
        ))}
      </div>
      <div className={`rounded-lg px-3 py-2 text-theme-xs ${overEstimate ? "bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400" : "bg-gray-50 text-gray-500 dark:bg-white/[0.02] dark:text-gray-400"}`}>
        Total being allocated: <span className="font-mono font-medium">{formatRm(totalSen)}</span>
        {" — "}rough P&amp;L estimate for {index.current_month_label} (unaudited): <span className="font-mono font-medium">{formatRm(index.current_month_rough_pl_estimate_sen)}</span>
        {overEstimate && " — this allocation is more than the rough estimate above. Still fine if you're allocating from profit built up in earlier months, but double-check the amounts first."}
      </div>
      <div className="flex justify-end">
        <Button type="submit" size="small" disabled={submitting}>{submitting ? "Saving…" : "Allocate"}</Button>
      </div>
    </form>
  );
}
