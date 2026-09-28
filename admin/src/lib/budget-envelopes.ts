import { apiFetch, apiUpload, ApiError } from "@/lib/api-client";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum — discretionary,
 * director-controlled budget tracking (Capital Rolling, Marketing
 * Budget, Maintenance/Operations, Company Savings), added by
 * re-grilling ADR-083 decision 11. Deliberately not a double-entry
 * engine — a categorized, append-only record with running balances.
 * All amounts are MYR sen.
 */
export interface BudgetEnvelope {
  id: number;
  name: string;
  /** Archived envelopes are hidden from the default active list but their entry history stays visible/exportable — never a hard delete, see the backend migration's own doc comment. */
  is_active: boolean;
  balance_sen: number;
}

export interface BudgetEnvelopeCategory {
  value: string;
  label: string;
  typical_sign: "positive" | "negative" | "either";
}

export interface BudgetEnvelopeIndex {
  envelopes: BudgetEnvelope[];
  categories: BudgetEnvelopeCategory[];
  /** The same lines `/admin/accounting/summary` already shows, for the current month — reference context for the "Allocate Monthly Profit" decision, deliberately not a single derived "net profit" figure. */
  current_month_summary: Record<string, number>;
  current_month_label: string;
  /** A rough, explicitly unaudited P&L estimate (sums the P&L-shaped lines only) — a soft-warning aid for Allocate Monthly Profit, never an authoritative figure. */
  current_month_rough_pl_estimate_sen: number;
}

export interface BudgetEnvelopeEntry {
  id: number;
  category: string;
  category_label: string;
  /** Signed — positive = money in, negative = money out. */
  amount_sen: number;
  description: string;
  has_receipt: boolean;
  reverses_entry_id: number | null;
  is_voided: boolean;
  created_by: string | null;
  created_at: string;
}

export function getBudgetEnvelopes(token: string) {
  return apiFetch<BudgetEnvelopeIndex>("/api/accounting/envelopes", { token });
}

export function createBudgetEnvelope(token: string, name: string) {
  return apiFetch<{ envelope: BudgetEnvelope }>("/api/accounting/envelopes", { method: "POST", token, body: { name } });
}

/** Rename and/or archive/reactivate — never a hard delete (a used envelope is protected at the DB layer regardless). */
export function updateBudgetEnvelope(token: string, envelopeId: number, values: { name?: string; is_active?: boolean }) {
  return apiFetch<{ envelope: BudgetEnvelope }>(`/api/accounting/envelopes/${envelopeId}`, { method: "PATCH", token, body: values });
}

export function getBudgetEnvelopeEntries(token: string, envelopeId: number, filters: { from?: string; to?: string } = {}) {
  const params = new URLSearchParams();
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  const qs = params.toString();

  return apiFetch<{ entries: BudgetEnvelopeEntry[] }>(`/api/accounting/envelopes/${envelopeId}/entries${qs ? `?${qs}` : ""}`, { token });
}

export interface RecordBudgetEnvelopeEntryValues {
  category: string;
  /** Always a positive magnitude — the backend applies the category's own sign. */
  amount_sen: number;
  description: string;
  /** Required only when category is "adjustment", the one category allowed either sign. */
  direction?: "in" | "out";
  receipt?: File | null;
}

export function recordBudgetEnvelopeEntry(token: string, envelopeId: number, values: RecordBudgetEnvelopeEntryValues) {
  const formData = new FormData();
  formData.append("category", values.category);
  formData.append("amount_sen", String(values.amount_sen));
  formData.append("description", values.description);
  if (values.direction) formData.append("direction", values.direction);
  if (values.receipt) formData.append("receipt", values.receipt);

  return apiUpload<{ entry: BudgetEnvelopeEntry; balance_sen: number }>(
    `/api/accounting/envelopes/${envelopeId}/entries`,
    formData,
    { token },
  );
}

/** Never edits/deletes the original entry — posts a new, negated entry referencing it. An already-voided entry is rejected. */
export function voidBudgetEnvelopeEntry(token: string, entryId: number, reason: string) {
  return apiFetch<{ reversal: BudgetEnvelopeEntry; balance_sen: number }>(
    `/api/accounting/envelope-entries/${entryId}/void`,
    { method: "POST", token, body: { reason } },
  );
}

export interface AllocateMonthlyProfitValues {
  period_label: string;
  allocations: Array<{ budget_envelope_id: number; amount_sen: number }>;
}

/** "Allocate Monthly Profit" — the founder decides the split manually each month, never an automatic formula. */
export function allocateMonthlyProfit(token: string, values: AllocateMonthlyProfitValues) {
  return apiFetch<{ entries: BudgetEnvelopeEntry[] }>(
    "/api/accounting/envelopes/allocate-monthly-profit",
    { method: "POST", token, body: values },
  );
}

/** Bearer-token-gated (private disk) — same Blob + object-URL pattern as `downloadSupplierTransferReceipt`. */
export async function downloadBudgetEnvelopeEntryReceipt(token: string, entryId: number, filename: string): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/api/accounting/envelope-entries/${entryId}/receipt`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  if (!response.ok) {
    throw new ApiError(response.status, undefined, `Download failed (${response.status})`);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
