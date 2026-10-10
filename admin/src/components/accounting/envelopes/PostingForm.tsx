"use client";

/**
 * ADR-083 2026-10-10 addendum, decision 3 — one form for every manual
 * posting type. The admin always types plain positive RM amounts; this
 * builds the signed lines each type needs. The backend re-checks every
 * rule (signs, sums, required fields, the repayment cap) and its 422
 * message is shown as-is.
 */

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { todayInKL } from "@/lib/date-range";
import { ApiError } from "@/lib/api-client";
import {
  formatRm,
  recordPosting,
  rmToSen,
  type BudgetEnvelope,
  type BudgetEnvelopeIndex,
  type PostingLine,
  type PostingType,
} from "@/lib/budget-envelopes";

const SELECT_CLASS =
  "h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-theme-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90";

const HINTS: Record<PostingType, string> = {
  funding: "Money a director lends the company, or pays in as share capital. Split it across envelopes in this one entry.",
  transfer: "Move budget from one envelope to another. The total stays the same.",
  expense: "A business cost paid from company money.",
  director_paid_expense: "A business cost a director paid from their own pocket. The envelope's budget is used, and the company now owes that director.",
  repayment: "The company pays back part of a director's loan. Cannot exceed what is owed.",
  distribution: "A dividend paid out to a shareholder.",
};

interface Props {
  token: string;
  index: BudgetEnvelopeIndex;
  defaultEnvelopeId: number | null;
  onRecorded: () => Promise<void> | void;
}

export function PostingForm({ token, index, defaultEnvelopeId, onRecorded }: Props) {
  const active = index.envelopes.filter((e) => e.is_active);
  const firstId = defaultEnvelopeId ?? active[0]?.id ?? 0;

  const [type, setType] = useState<PostingType>("expense");
  const [envelopeId, setEnvelopeId] = useState(firstId);
  const [toEnvelopeId, setToEnvelopeId] = useState(active.find((e) => e.id !== firstId)?.id ?? 0);
  const [amount, setAmount] = useState("");
  const [splits, setSplits] = useState<Record<number, string>>({});
  const [counterparty, setCounterparty] = useState("");
  const [fundType, setFundType] = useState("loan");
  const [expenseCategory, setExpenseCategory] = useState("");
  const [transactionDate, setTransactionDate] = useState(todayInKL());
  const [description, setDescription] = useState("");
  const [referenceNo, setReferenceNo] = useState("");
  const [receipt, setReceipt] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const needsDirector = type === "funding" || type === "director_paid_expense" || type === "repayment" || type === "distribution";
  const needsCategory = type === "expense" || type === "director_paid_expense";
  const owed = index.loan_balances.find((b) => b.counterparty === counterparty)?.balance_sen;
  const splitTotal = Object.values(splits).reduce((sum, v) => sum + (Number.isFinite(rmToSen(v)) ? rmToSen(v) : 0), 0);

  function buildLines(): PostingLine[] | string {
    if (type === "funding") {
      const lines = Object.entries(splits)
        .filter(([, rm]) => rm.trim() !== "")
        .map(([id, rm]) => ({ budget_envelope_id: Number(id), amount_sen: rmToSen(rm) }));
      if (lines.length === 0) return "Enter the amount for at least one envelope.";
      if (lines.some((l) => !Number.isFinite(l.amount_sen) || l.amount_sen < 1)) return "Each envelope amount must be a valid RM amount.";
      return lines;
    }

    const sen = rmToSen(amount);
    if (!Number.isFinite(sen) || sen < 1) return "Enter a valid amount (RM).";

    switch (type) {
      case "transfer":
        if (toEnvelopeId === envelopeId) return "Choose two different envelopes.";
        return [
          { budget_envelope_id: envelopeId, amount_sen: -sen },
          { budget_envelope_id: toEnvelopeId, amount_sen: sen },
        ];
      case "director_paid_expense":
        return [
          { budget_envelope_id: envelopeId, amount_sen: sen },
          { budget_envelope_id: envelopeId, amount_sen: -sen },
        ];
      default:
        return [{ budget_envelope_id: envelopeId, amount_sen: -sen }];
    }
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    const lines = buildLines();
    if (typeof lines === "string") {
      setError(lines);
      return;
    }
    if (!description.trim()) {
      setError("Enter a description.");
      return;
    }

    setSubmitting(true);
    try {
      await recordPosting(token, {
        type,
        transaction_date: transactionDate,
        description: description.trim(),
        lines,
        counterparty: needsDirector ? counterparty : undefined,
        fund_type: type === "funding" ? fundType : undefined,
        expense_category: needsCategory ? expenseCategory : undefined,
        reference_no: referenceNo.trim() || undefined,
        receipt,
      });
      setAmount("");
      setSplits({});
      setDescription("");
      setReferenceNo("");
      setReceipt(null);
      await onRecorded();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  const envelopeSelect = (id: string, value: number, onChange: (v: number) => void, envelopes: BudgetEnvelope[] = active) => (
    <select id={id} value={value} onChange={(e) => onChange(Number(e.target.value))} className={SELECT_CLASS}>
      {envelopes.map((env) => (
        <option key={env.id} value={env.id}>{env.name} ({formatRm(env.balance_sen)})</option>
      ))}
    </select>
  );

  return (
    <form onSubmit={handleSubmit} className="mb-6 space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
      {error && (
        <p className="rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Label htmlFor="posting_type">What happened?</Label>
          <select id="posting_type" value={type} onChange={(e) => setType(e.target.value as PostingType)} className={SELECT_CLASS}>
            {index.posting_types.map((t) => (
              <option key={t.value} value={t.value}>{t.label}</option>
            ))}
          </select>
          <span className="mt-1 block text-theme-xs text-gray-400">{HINTS[type]}</span>
        </div>

        {needsDirector && (
          <div>
            <Label htmlFor="posting_counterparty">{type === "distribution" ? "Paid to" : "Director"}</Label>
            <select id="posting_counterparty" value={counterparty} onChange={(e) => setCounterparty(e.target.value)} className={SELECT_CLASS} required>
              <option value="">Select a director</option>
              {index.directors.map((d) => (
                <option key={d.value} value={d.value}>{d.label}</option>
              ))}
            </select>
            {type === "repayment" && owed !== undefined && (
              <span className="mt-1 block text-theme-xs text-gray-400">The company owes them {formatRm(owed)}.</span>
            )}
          </div>
        )}

        {type === "funding" && (
          <div>
            <Label htmlFor="posting_fund_type">Kind of money</Label>
            <select id="posting_fund_type" value={fundType} onChange={(e) => setFundType(e.target.value)} className={SELECT_CLASS}>
              {index.fund_types.map((f) => (
                <option key={f.value} value={f.value}>{f.label}</option>
              ))}
            </select>
          </div>
        )}

        {needsCategory && (
          <div>
            <Label htmlFor="posting_category">Expense category</Label>
            <select id="posting_category" value={expenseCategory} onChange={(e) => setExpenseCategory(e.target.value)} className={SELECT_CLASS} required>
              <option value="">Select a category</option>
              {index.expense_categories.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>
          </div>
        )}

        {type === "funding" ? (
          <div className="space-y-2 sm:col-span-2">
            <p className="text-theme-xs font-medium text-gray-600 dark:text-gray-400">Split across envelopes (RM)</p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {active.map((env) => (
                <div key={env.id}>
                  <Label htmlFor={`split_${env.id}`}>{env.name}</Label>
                  <Input
                    id={`split_${env.id}`}
                    value={splits[env.id] ?? ""}
                    onChange={(e) => setSplits((cur) => ({ ...cur, [env.id]: e.target.value }))}
                    placeholder="0.00"
                  />
                </div>
              ))}
            </div>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              Total received: <span className="font-mono font-medium">{formatRm(splitTotal)}</span>
            </p>
          </div>
        ) : (
          <>
            <div>
              <Label htmlFor="posting_envelope">{type === "transfer" ? "From envelope" : "Envelope"}</Label>
              {envelopeSelect("posting_envelope", envelopeId, setEnvelopeId)}
            </div>
            {type === "transfer" && (
              <div>
                <Label htmlFor="posting_to_envelope">To envelope</Label>
                {envelopeSelect("posting_to_envelope", toEnvelopeId, setToEnvelopeId)}
              </div>
            )}
            <div>
              <Label htmlFor="posting_amount">Amount (RM)</Label>
              <Input id="posting_amount" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="50.00" required />
            </div>
          </>
        )}

        <div>
          <Label htmlFor="posting_date">Date the money moved</Label>
          <Input id="posting_date" type="date" value={transactionDate} onChange={(e) => setTransactionDate(e.target.value)} max={todayInKL()} required />
        </div>
        <div className="sm:col-span-2">
          <Label htmlFor="posting_description">Description</Label>
          <Input id="posting_description" value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Tune Talk customer-service number" required />
        </div>
        <div>
          <Label htmlFor="posting_reference">Reference number</Label>
          <Input id="posting_reference" value={referenceNo} onChange={(e) => setReferenceNo(e.target.value)} placeholder="Optional — invoice/bank reference" />
        </div>
        <div>
          <Label htmlFor="posting_receipt">Receipt</Label>
          <input
            id="posting_receipt"
            type="file"
            accept=".jpg,.jpeg,.png,.pdf"
            onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
            className="block w-full text-theme-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-theme-xs dark:text-gray-400 dark:file:bg-gray-800"
          />
        </div>
      </div>
      <div className="flex justify-end">
        <Button type="submit" size="small" disabled={submitting}>{submitting ? "Recording…" : "Record"}</Button>
      </div>
    </form>
  );
}
