import { apiFetch, apiUpload } from "@/lib/api-client";

/**
 * ADR-110 PR-B, fills ADR-083 decision 7 — CHIP Settlements. Three
 * independent numbers per settlement window, deliberately never
 * conflated: `expected_*` (this platform's own records), `file_*`
 * (CHIP's own reported totals), `actual_bank_amount_sen` (the
 * founder's own manually-entered real bank figure).
 */
export interface PaymentSettlement {
  id: number;
  date_from: string;
  date_to: string;
  expected_gross_sen: number;
  expected_fee_sen: number;
  expected_net_sen: number;
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
  amount_sen: number;
  fee_sen: number;
  net_amount_sen: number;
  acquirer: string;
  settled_on: string;
}

/** ADR-110 PR-B addendum — a re-uploaded or date-overlapping file never double-counts; this is what makes that visible. */
export interface SettlementIngestResult {
  settlement: PaymentSettlement;
  newly_matched_count: number;
  newly_unmatched_count: number;
  already_reconciled_skipped_count: number;
  unmatched_transaction_ids: string[];
  paid_but_not_settled: { reference: string; amount_sen: number }[];
}

export function listPaymentSettlements(token: string) {
  return apiFetch<PaymentSettlement[]>("/api/accounting/settlements", { token });
}

export function getPaymentSettlement(token: string, id: number) {
  return apiFetch<{ settlement: PaymentSettlement; transactions: ChipSettledTransaction[] }>(
    `/api/accounting/settlements/${id}`,
    { token },
  );
}

export function uploadPaymentSettlement(token: string, file: File) {
  const formData = new FormData();
  formData.append("file", file);

  return apiUpload<SettlementIngestResult>("/api/accounting/settlements", formData, { token });
}

export interface UpdatePaymentSettlementValues {
  actual_bank_amount_sen: number;
  status: "matched" | "variance";
  variance_note?: string;
}

export function updatePaymentSettlement(token: string, id: number, values: UpdatePaymentSettlementValues) {
  return apiFetch<PaymentSettlement>(`/api/accounting/settlements/${id}`, { method: "PATCH", body: values, token });
}
