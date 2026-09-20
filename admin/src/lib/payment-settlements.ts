import { apiFetch, apiUpload } from "@/lib/api-client";

/** Plain Laravel `LengthAwarePaginator` JSON shape — mirrors `OrderPage` (`lib/orders.ts`). */
export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

/**
 * ADR-110 PR-B, fills ADR-083 decision 7 — CHIP Settlements. `status`
 * is fully computed at ingest (a per-transaction gross comparison
 * between `matched_*` — this platform's own records, summed ONLY over
 * the transactions this settlement actually matched — and `file_*`,
 * CHIP's own reported totals) — never admin-typed, per this ADR's own
 * same-day "automatic reconciliation" addendum.
 * `actual_bank_amount_sen` is a purely optional founder annotation
 * (no cadence, never drives `status`).
 */
export interface PaymentSettlement {
  id: number;
  date_from: string;
  date_to: string;
  matched_gross_sen: number;
  matched_fee_sen: number;
  matched_net_sen: number;
  file_gross_sen: number;
  file_fee_sen: number;
  file_net_sen: number;
  actual_bank_amount_sen: number | null;
  status: "pending" | "matched" | "variance";
  variance_note: string | null;
  original_filename: string;
  created_at: string;
}

export interface ChipSettledTransaction {
  id: number;
  transaction_id: string;
  matched_type: "order" | "membership_checkout_attempt" | "wallet_topup_attempt" | null;
  matched_id: number | null;
  /** Our own gross for the matched record — null when unmatched. Compare to `amount_sen` for a per-row mismatch. */
  local_gross_sen: number | null;
  amount_sen: number;
  fee_sen: number;
  net_amount_sen: number;
  acquirer: string;
  settled_on: string;
}

/** A CHIP-paid record in this window that has never appeared in ANY settlement file uploaded to date — recomputed live, never a stale snapshot. */
export interface PaidButNotSettledRow {
  reference: string;
  amount_sen: number;
}

/** ADR-110 PR-B addendum — a re-uploaded or date-overlapping file never double-counts; this is what makes that visible. */
export interface SettlementIngestResult {
  settlement: PaymentSettlement;
  newly_matched_count: number;
  newly_unmatched_count: number;
  already_reconciled_skipped_count: number;
  unmatched_transaction_ids: string[];
  paid_but_not_settled: PaidButNotSettledRow[];
}

export function listPaymentSettlements(token: string, page = 1) {
  return apiFetch<Paginated<PaymentSettlement>>(`/api/accounting/settlements?page=${page}`, { token });
}

export function getPaymentSettlement(token: string, id: number, transactionsPage = 1) {
  return apiFetch<{
    settlement: PaymentSettlement;
    transactions: Paginated<ChipSettledTransaction>;
    paid_but_not_settled: PaidButNotSettledRow[];
  }>(`/api/accounting/settlements/${id}?page=${transactionsPage}`, { token });
}

export function uploadPaymentSettlement(token: string, file: File) {
  const formData = new FormData();
  formData.append("file", file);

  return apiUpload<SettlementIngestResult>("/api/accounting/settlements", formData, { token });
}

/** ADR-110 PR-B addendum — a purely optional founder annotation, no cadence, never touches `status` (computed, read-only). */
export interface UpdatePaymentSettlementValues {
  actual_bank_amount_sen?: number;
  variance_note?: string;
}

export function updatePaymentSettlement(token: string, id: number, values: UpdatePaymentSettlementValues) {
  return apiFetch<PaymentSettlement>(`/api/accounting/settlements/${id}`, { method: "PATCH", body: values, token });
}
