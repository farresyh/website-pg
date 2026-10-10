import { apiFetch, apiUpload, ApiError } from "@/lib/api-client";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * ADR-083 Envelope Ledger, reshaped by the 2026-10-10 addendum: every action
 * is one posting (a header) with signed lines into or out of envelopes. An
 * envelope is an allocation ("what this money is for"), never a location —
 * supplier top-ups and CHIP payouts never appear here. All amounts are MYR sen.
 */
export interface BudgetEnvelope {
  id: number;
  name: string;
  /** Archived envelopes are hidden from the active grid; their history stays. Archiving needs a zero balance. */
  is_active: boolean;
  balance_sen: number;
}

export interface Option {
  value: string;
  label: string;
}

export type PostingType = "funding" | "transfer" | "expense" | "director_paid_expense" | "repayment" | "distribution";

export interface LoanBalance {
  counterparty: string;
  label: string;
  balance_sen: number;
}

export interface BudgetEnvelopeIndex {
  envelopes: BudgetEnvelope[];
  posting_types: Option[];
  directors: Option[];
  fund_types: Option[];
  expense_categories: Option[];
  /** What the company owes each director, computed from postings. */
  loan_balances: LoanBalance[];
}

/** One line in one envelope, with its posting's details. */
export interface BudgetEnvelopeEntry {
  id: number;
  posting_id: number;
  type: string;
  type_label: string;
  /** This line's signed amount — positive into the envelope, negative out. */
  amount_sen: number;
  posting_amount_sen: number;
  transaction_date: string;
  description: string;
  counterparty: string | null;
  counterparty_label: string | null;
  fund_type_label: string | null;
  expense_category_label: string | null;
  reference_no: string | null;
  has_receipt: boolean;
  reverses_posting_id: number | null;
  is_voided: boolean;
  created_by: string | null;
  created_at: string;
}

export interface PostingLine {
  budget_envelope_id: number;
  /** Signed sen. */
  amount_sen: number;
}

export interface RecordPostingValues {
  type: PostingType;
  transaction_date: string;
  description: string;
  lines: PostingLine[];
  counterparty?: string;
  fund_type?: string;
  expense_category?: string;
  reference_no?: string;
  receipt?: File | null;
}

export function getBudgetEnvelopes(token: string) {
  return apiFetch<BudgetEnvelopeIndex>("/api/accounting/envelopes", { token });
}

export function createBudgetEnvelope(token: string, name: string) {
  return apiFetch<{ envelope: BudgetEnvelope }>("/api/accounting/envelopes", { method: "POST", token, body: { name } });
}

/** Rename and/or archive/reactivate — never a hard delete. */
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

/** The backend enforces every type's rules (signs, sums, required fields) and answers 422 with the reason. */
export function recordPosting(token: string, values: RecordPostingValues) {
  const formData = new FormData();
  formData.append("type", values.type);
  formData.append("transaction_date", values.transaction_date);
  formData.append("description", values.description);
  values.lines.forEach((line, i) => {
    formData.append(`lines[${i}][budget_envelope_id]`, String(line.budget_envelope_id));
    formData.append(`lines[${i}][amount_sen]`, String(line.amount_sen));
  });
  if (values.counterparty) formData.append("counterparty", values.counterparty);
  if (values.fund_type) formData.append("fund_type", values.fund_type);
  if (values.expense_category) formData.append("expense_category", values.expense_category);
  if (values.reference_no) formData.append("reference_no", values.reference_no);
  if (values.receipt) formData.append("receipt", values.receipt);

  return apiUpload<{ posting: { id: number } }>("/api/accounting/envelope-postings", formData, { token });
}

/** Reverses every line of the posting; the original stays. A posting is voided at most once. */
export function voidPosting(token: string, postingId: number, reason: string) {
  return apiFetch<{ reversal: { id: number } }>(`/api/accounting/envelope-postings/${postingId}/void`, { method: "POST", token, body: { reason } });
}

/** Bearer-token-gated (private disk) — Blob + object-URL download. */
export async function downloadPostingReceipt(token: string, postingId: number): Promise<void> {
  await downloadBlob(token, `/api/accounting/envelope-postings/${postingId}/receipt`, `envelope-posting-${postingId}-receipt`);
}

export async function downloadEnvelopeExport(token: string): Promise<void> {
  await downloadBlob(token, "/api/accounting/envelopes/export", "envelope-ledger.csv");
}

async function downloadBlob(token: string, path: string, filename: string): Promise<void> {
  const response = await fetch(`${API_BASE_URL}${path}`, { headers: { Authorization: `Bearer ${token}` } });

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

export function formatRm(sen: number): string {
  const formatted = `RM ${(Math.abs(sen) / 100).toFixed(2)}`;
  return sen < 0 ? `(${formatted})` : formatted;
}

/** "12.34" → 1234; NaN for anything that isn't a plain amount. */
export function rmToSen(rm: string): number {
  return /^\d+(\.\d{1,2})?$/.test(rm.trim()) ? Math.round(parseFloat(rm) * 100) : NaN;
}
