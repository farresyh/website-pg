"use client";

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum — discretionary,
 * director-controlled budget tracking (Capital Rolling, Marketing
 * Budget, Maintenance/Operations, Company Savings), added by
 * re-grilling ADR-083 decision 11 ("the platform does not model
 * equity, capital or drawings"). Deliberately not a double-entry
 * engine — categorized, append-only entries with a running balance per
 * envelope, "beginner friendly" by explicit founder request: amounts
 * are always typed as a plain positive magnitude, the category itself
 * decides whether it credits or debits (see `typical_sign`).
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Tag } from "@/components/ui/tag";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  getBudgetEnvelopes,
  createBudgetEnvelope,
  getBudgetEnvelopeEntries,
  recordBudgetEnvelopeEntry,
  voidBudgetEnvelopeEntry,
  allocateMonthlyProfit,
  downloadBudgetEnvelopeEntryReceipt,
  type BudgetEnvelope,
  type BudgetEnvelopeCategory,
  type BudgetEnvelopeEntry,
} from "@/lib/budget-envelopes";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

function formatRm(sen: number): string {
  const negative = sen < 0;
  const formatted = `RM ${(Math.abs(sen) / 100).toFixed(2)}`;
  return negative ? `(${formatted})` : formatted;
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString("en-MY", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

const SUMMARY_LINE_LABELS: Record<string, string> = {
  sales_revenue_sen: "Sales revenue",
  membership_revenue_sen: "Membership revenue",
  cogs_sen: "COGS",
  payment_processing_gain_loss_sen: "Payment processing net gain/(loss)",
  supplier_prepaid_topup_sen: "Supplier prepaid — top-up",
  supplier_prepaid_fx_variance_sen: "Supplier prepaid — FX variance true-up",
  affiliate_commission_expense_sen: "Affiliate commission expense",
  voucher_liability_issued_sen: "Voucher liability issued",
};

export default function EnvelopeLedgerPage() {
  const router = useRouter();
  const session = useClientSession();
  const token = session?.token ?? null;

  const [envelopes, setEnvelopes] = useState<BudgetEnvelope[]>([]);
  const [categories, setCategories] = useState<BudgetEnvelopeCategory[]>([]);
  const [monthSummary, setMonthSummary] = useState<Record<string, number>>({});
  const [monthLabel, setMonthLabel] = useState("");
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [entries, setEntries] = useState<BudgetEnvelopeEntry[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState<number | null>(null);

  const [newEnvelopeName, setNewEnvelopeName] = useState("");
  const [showNewEnvelope, setShowNewEnvelope] = useState(false);

  const [showRecordForm, setShowRecordForm] = useState(false);
  const [category, setCategory] = useState("");
  const [amount, setAmount] = useState("");
  const [description, setDescription] = useState("");
  const [direction, setDirection] = useState<"in" | "out">("out");
  const [receipt, setReceipt] = useState<File | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const [showAllocate, setShowAllocate] = useState(false);
  const [allocationAmounts, setAllocationAmounts] = useState<Record<number, string>>({});
  const [allocating, setAllocating] = useState(false);

  const [voidingId, setVoidingId] = useState<number | null>(null);
  const [voidReason, setVoidReason] = useState("");

  function loadIndex(t: string) {
    return getBudgetEnvelopes(t)
      .then((data) => {
        setEnvelopes(data.envelopes);
        setCategories(data.categories);
        setMonthSummary(data.current_month_summary);
        setMonthLabel(data.current_month_label);
        setSelectedId((current) => current ?? data.envelopes[0]?.id ?? null);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load envelopes."));
  }

  function loadEntries(t: string, envelopeId: number) {
    return getBudgetEnvelopeEntries(t, envelopeId)
      .then((data) => setEntries(data.entries))
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load entries."));
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    loadIndex(s.token);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (token && selectedId) loadEntries(token, selectedId);
  }, [token, selectedId]);

  const selectedEnvelope = envelopes.find((e) => e.id === selectedId) ?? null;
  const selectedCategory = categories.find((c) => c.value === category) ?? null;

  async function handleCreateEnvelope(e: React.FormEvent) {
    e.preventDefault();
    if (!token || !newEnvelopeName.trim()) return;
    setError(null);
    try {
      await createBudgetEnvelope(token, newEnvelopeName.trim());
      setNewEnvelopeName("");
      setShowNewEnvelope(false);
      await loadIndex(token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not create envelope.");
    }
  }

  async function handleRecordEntry(e: React.FormEvent) {
    e.preventDefault();
    if (!token || !selectedEnvelope || !selectedCategory) return;
    setError(null);

    const amountSen = Math.round(parseFloat(amount) * 100);
    if (!Number.isFinite(amountSen) || amountSen < 1) {
      setError("Enter a valid amount (RM).");
      return;
    }
    if (!description.trim()) {
      setError("Enter a description.");
      return;
    }

    setSubmitting(true);
    try {
      await recordBudgetEnvelopeEntry(token, selectedEnvelope.id, {
        category,
        amount_sen: amountSen,
        description: description.trim(),
        direction: selectedCategory.typical_sign === "either" ? direction : undefined,
        receipt,
      });
      setCategory("");
      setAmount("");
      setDescription("");
      setReceipt(null);
      setShowRecordForm(false);
      await Promise.all([loadIndex(token), loadEntries(token, selectedEnvelope.id)]);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleVoid(entryId: number) {
    if (!token || !selectedEnvelope || !voidReason.trim()) {
      setError("Enter a reason to void this entry.");
      return;
    }
    setError(null);
    try {
      await voidBudgetEnvelopeEntry(token, entryId, voidReason.trim());
      setVoidingId(null);
      setVoidReason("");
      await Promise.all([loadIndex(token), loadEntries(token, selectedEnvelope.id)]);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not void this entry.");
    }
  }

  async function handleAllocate(e: React.FormEvent) {
    e.preventDefault();
    if (!token) return;
    setError(null);

    const allocations = Object.entries(allocationAmounts)
      .map(([envelopeId, rm]) => ({ budget_envelope_id: Number(envelopeId), amount_sen: Math.round(parseFloat(rm) * 100) }))
      .filter((a) => Number.isFinite(a.amount_sen) && a.amount_sen > 0);

    if (allocations.length === 0) {
      setError("Enter at least one envelope's allocation amount.");
      return;
    }

    setAllocating(true);
    try {
      await allocateMonthlyProfit(token, { period_label: monthLabel, allocations });
      setAllocationAmounts({});
      setShowAllocate(false);
      await loadIndex(token);
      if (selectedId) await loadEntries(token, selectedId);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not allocate profit.");
    } finally {
      setAllocating(false);
    }
  }

  async function handleDownloadReceipt(entryId: number) {
    if (!token) return;
    setDownloading(entryId);
    try {
      await downloadBudgetEnvelopeEntryReceipt(token, entryId, `envelope-entry-${entryId}-receipt`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Download failed.");
    } finally {
      setDownloading(null);
    }
  }

  async function handleExportClick() {
    if (!token) return;
    const response = await fetch(`${API_BASE_URL}/api/accounting/envelopes/export`, { headers: { Authorization: `Bearer ${token}` } });
    if (!response.ok) {
      setError("Export failed.");
      return;
    }
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = "envelope-ledger.csv";
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  if (!token) {
    return <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>;
  }

  return (
    <div>
      <div className="mb-6 flex items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Envelope Ledger</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Capital, marketing, maintenance and dividends — money in and out, so an auditor can see exactly what every ringgit was for.
          </p>
        </div>
        <button type="button" onClick={() => void handleExportClick()} className="whitespace-nowrap text-theme-sm text-brand-500 hover:underline">
          Export CSV →
        </button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}

      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {envelopes.map((envelope) => (
          <button
            key={envelope.id}
            type="button"
            onClick={() => setSelectedId(envelope.id)}
            className={`rounded-2xl border p-4 text-left transition ${
              selectedId === envelope.id
                ? "border-brand-500 bg-brand-50 dark:border-brand-400 dark:bg-brand-500/10"
                : "border-gray-200 bg-white hover:border-gray-300 dark:border-gray-800 dark:bg-white/[0.03]"
            }`}
          >
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">{envelope.name}</p>
            <p className="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90">{formatRm(envelope.balance_sen)}</p>
          </button>
        ))}
      </div>

      {!showNewEnvelope ? (
        <button type="button" onClick={() => setShowNewEnvelope(true)} className="mb-6 text-theme-sm text-brand-500 hover:underline">
          + New envelope
        </button>
      ) : (
        <form onSubmit={handleCreateEnvelope} className="mb-6 flex items-end gap-2">
          <div>
            <Label htmlFor="new_envelope_name">Envelope name</Label>
            <Input id="new_envelope_name" value={newEnvelopeName} onChange={(e) => setNewEnvelopeName(e.target.value)} placeholder="Staff Bonus" />
          </div>
          <Button type="submit" size="small">Create</Button>
          <Button type="button" size="small" variant="outlined" onClick={() => setShowNewEnvelope(false)}>Cancel</Button>
        </form>
      )}

      {selectedEnvelope && (
        <>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-base font-semibold text-gray-800 dark:text-white/90">{selectedEnvelope.name}</h2>
            <div className="flex gap-2">
              <Button size="small" variant="outlined" onClick={() => setShowAllocate((v) => !v)}>
                Allocate Monthly Profit
              </Button>
              <Button size="small" onClick={() => setShowRecordForm((v) => !v)}>
                {showRecordForm ? "Cancel" : "Record Entry"}
              </Button>
            </div>
          </div>

          {showAllocate && (
            <form onSubmit={handleAllocate} className="mb-6 space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
              <p className="text-theme-xs font-medium text-gray-600 dark:text-gray-400">
                {monthLabel} — reference figures from the Monthly Summary (not a single derived &quot;net profit&quot; — you decide the split).
              </p>
              <div className="grid grid-cols-2 gap-x-6 gap-y-1 text-theme-xs sm:grid-cols-4">
                {Object.entries(monthSummary).map(([key, value]) => (
                  <div key={key}>
                    <span className="text-gray-400">{SUMMARY_LINE_LABELS[key] ?? key}</span>
                    <p className="font-mono text-gray-700 dark:text-gray-300">{formatRm(value)}</p>
                  </div>
                ))}
              </div>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {envelopes.map((envelope) => (
                  <div key={envelope.id}>
                    <Label htmlFor={`alloc_${envelope.id}`}>{envelope.name} (RM)</Label>
                    <Input
                      id={`alloc_${envelope.id}`}
                      value={allocationAmounts[envelope.id] ?? ""}
                      onChange={(e) => setAllocationAmounts((cur) => ({ ...cur, [envelope.id]: e.target.value }))}
                      placeholder="0.00"
                    />
                  </div>
                ))}
              </div>
              <div className="flex justify-end">
                <Button type="submit" size="small" disabled={allocating}>{allocating ? "Saving…" : "Allocate"}</Button>
              </div>
            </form>
          )}

          {showRecordForm && (
            <form onSubmit={handleRecordEntry} className="mb-6 space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <Label htmlFor="entry_category">Category</Label>
                  <select
                    id="entry_category"
                    value={category}
                    onChange={(e) => setCategory(e.target.value)}
                    className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-theme-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
                    required
                  >
                    <option value="">Select a category</option>
                    {categories.map((c) => (
                      <option key={c.value} value={c.value}>{c.label}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <Label htmlFor="entry_amount">Amount (RM)</Label>
                  <Input id="entry_amount" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="50.00" required />
                </div>
                {selectedCategory?.typical_sign === "either" && (
                  <div>
                    <Label htmlFor="entry_direction">Direction</Label>
                    <select
                      id="entry_direction"
                      value={direction}
                      onChange={(e) => setDirection(e.target.value as "in" | "out")}
                      className="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-theme-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
                    >
                      <option value="in">Money in</option>
                      <option value="out">Money out</option>
                    </select>
                  </div>
                )}
                <div className="sm:col-span-2">
                  <Label htmlFor="entry_description">Description</Label>
                  <Input id="entry_description" value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Facebook Ads — September" required />
                </div>
                <div>
                  <Label htmlFor="entry_receipt">Receipt</Label>
                  <input
                    id="entry_receipt"
                    type="file"
                    accept=".jpg,.jpeg,.png,.pdf"
                    onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
                    className="block w-full text-theme-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-theme-xs dark:text-gray-400 dark:file:bg-gray-800"
                  />
                </div>
              </div>
              <div className="flex justify-end">
                <Button type="submit" size="small" disabled={submitting}>{submitting ? "Recording…" : "Record Entry"}</Button>
              </div>
            </form>
          )}

          <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div className="max-w-full overflow-x-auto">
              <table className="w-full text-left text-theme-sm">
                <thead className="bg-gray-50 dark:bg-gray-900">
                  <tr>
                    <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</th>
                    <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Category</th>
                    <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Amount</th>
                    <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Description</th>
                    <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">By</th>
                    <th className="px-4 py-2 text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {entries.map((entry) => {
                    const isReversal = entry.reverses_entry_id !== null;
                    return (
                      <tr key={entry.id} className={entry.is_voided ? "opacity-60" : undefined}>
                        <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{formatDate(entry.created_at)}</td>
                        <td className="px-4 py-2 text-gray-700 dark:text-gray-300">
                          {entry.category_label}
                          {entry.is_voided && <Tag severity="danger" className="ml-2">Voided</Tag>}
                          {isReversal && <Tag severity="warn" className="ml-2">Void reversal</Tag>}
                        </td>
                        <td className={`px-4 py-2 font-medium ${entry.amount_sen < 0 ? "text-error-600 dark:text-error-400" : "text-success-600 dark:text-success-400"} ${entry.is_voided ? "line-through" : ""}`}>
                          {entry.amount_sen > 0 ? "+" : ""}{formatRm(entry.amount_sen)}
                        </td>
                        <td className="px-4 py-2 text-gray-600 dark:text-gray-300">{entry.description}</td>
                        <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{entry.created_by ?? "—"}</td>
                        <td className="px-4 py-2">
                          <div className="flex items-center gap-2">
                            {entry.has_receipt && (
                              <Button type="button" size="small" variant="outlined" disabled={downloading === entry.id} onClick={() => handleDownloadReceipt(entry.id)}>
                                {downloading === entry.id ? "…" : "Receipt"}
                              </Button>
                            )}
                            {!entry.is_voided && !isReversal && (
                              <Button type="button" size="small" variant="outlined" onClick={() => { setVoidingId(voidingId === entry.id ? null : entry.id); setVoidReason(""); }}>
                                {voidingId === entry.id ? "Cancel" : "Void…"}
                              </Button>
                            )}
                          </div>
                          {voidingId === entry.id && (
                            <div className="mt-2 flex items-center gap-2">
                              <Input value={voidReason} onChange={(e) => setVoidReason(e.target.value)} placeholder="Reason (required)" className="w-56" />
                              <Button type="button" size="small" severity="danger" onClick={() => handleVoid(entry.id)}>Void Entirely</Button>
                            </div>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                  {entries.length === 0 && (
                    <tr>
                      <td colSpan={6} className="px-4 py-6 text-center text-gray-400">No entries recorded yet.</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
