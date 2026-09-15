import { apiFetch, apiUpload, ApiError } from "@/lib/api-client";

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";

/**
 * ADR-083 decision 2 (PR-1): the supplier funding ledger — under
 * `/accounting`, not `/middleware` (bookkeeping, not supplier-integration
 * config; see `SupplierTransferController`'s own docblock). `amount`
 * fields are strings — decimal(18,4)/foreign-currency, never MYR sen.
 *
 * 2026-09-15 addendum: `supplier_fee` (nullable) — the supplier's own
 * deposit-side cut (e.g. Digiflazz's flat IDR fee), distinct from
 * `fee_myr` (Wise/Airwallex's fee). `amount_foreign_received` stays
 * gross (the literal, receipt-verifiable figure) — the ledger credits
 * net (`amount_foreign_received - supplier_fee`) server-side.
 * `voided_at`/`void_reason` — set once this transfer's money is
 * confirmed to have never reached the supplier at all; `adjustments`
 * is every `MANUAL_ADJUSTMENT` ledger entry correcting this transfer
 * (never an edit to the fields above — see `SupplierLedgerAdjustment`).
 */
export interface SupplierTransfer {
  id: number;
  supplier_id: number;
  source_channel: "wise" | "airwallex" | "bank";
  amount_myr_sent: number;
  fee_myr: number;
  currency: string;
  amount_foreign_received: string;
  supplier_fee: string | null;
  effective_rate: string | null;
  receipt_path: string | null;
  reference_no: string | null;
  voided_at: string | null;
  void_reason: string | null;
  created_by: number | null;
  created_at: string;
  adjustments: SupplierLedgerAdjustment[];
}

/** A `MANUAL_ADJUSTMENT` ledger entry — always signed, always tied back to the transfer it corrects, never an edit of that transfer's own fields. */
export interface SupplierLedgerAdjustment {
  id: number;
  amount: string;
  currency: string;
  reason: string;
  created_by: number | null;
  created_at: string;
}

export interface SupplierTransfersPage {
  data: SupplierTransfer[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface SupplierFundingLedger {
  ledger_balance: string;
  currency: string;
  transfers: SupplierTransfersPage;
}

export function getSupplierTransfers(token: string, supplierId: number, page?: number) {
  const qs = page ? `?page=${page}` : "";

  return apiFetch<SupplierFundingLedger>(`/api/accounting/suppliers/${supplierId}/transfers${qs}`, { token });
}

export interface RecordSupplierTransferValues {
  source_channel: "wise" | "airwallex" | "bank";
  amount_myr_sent: number;
  fee_myr?: number;
  currency: string;
  amount_foreign_received: string;
  /** 2026-09-15 addendum — the supplier's own deposit-side cut, optional. */
  supplier_fee?: string | null;
  reference_no?: string | null;
  receipt?: File | null;
}

export function recordSupplierTransfer(token: string, supplierId: number, values: RecordSupplierTransferValues) {
  const formData = new FormData();
  formData.append("source_channel", values.source_channel);
  formData.append("amount_myr_sent", String(values.amount_myr_sent));
  formData.append("fee_myr", String(values.fee_myr ?? 0));
  formData.append("currency", values.currency);
  formData.append("amount_foreign_received", values.amount_foreign_received);
  if (values.supplier_fee) formData.append("supplier_fee", values.supplier_fee);
  if (values.reference_no) formData.append("reference_no", values.reference_no);
  if (values.receipt) formData.append("receipt", values.receipt);

  return apiUpload<{ transfer: SupplierTransfer; ledger_balance: string }>(
    `/api/accounting/suppliers/${supplierId}/transfers`,
    formData,
    { token },
  );
}

/**
 * 2026-09-15 addendum — "Adjust": a partial, signed correction against
 * an already-recorded transfer (e.g. a missed supplier fee). Never
 * edits the transfer itself — writes a new `MANUAL_ADJUSTMENT` ledger
 * entry referencing it.
 */
export function adjustSupplierTransfer(token: string, transferId: number, amount: string, reason: string) {
  return apiFetch<{ entry: SupplierLedgerAdjustment; ledger_balance: string }>(
    `/api/accounting/supplier-transfers/${transferId}/adjust`,
    { method: "POST", token, body: { amount, reason } },
  );
}

/**
 * 2026-09-15 addendum — "Void Entirely": the money behind this
 * transfer never reached the supplier at all. Reverses its full net
 * ledger contribution and marks the transfer `voided_at` — never
 * possible to call twice on the same transfer (backend rejects once
 * `voided_at` is set).
 */
export function voidSupplierTransfer(token: string, transferId: number, reason: string) {
  return apiFetch<{ transfer: SupplierTransfer; entry: SupplierLedgerAdjustment; ledger_balance: string }>(
    `/api/accounting/supplier-transfers/${transferId}/void`,
    { method: "POST", token, body: { reason } },
  );
}

/**
 * Bearer-token-gated route (`accounting_disk` is deliberately private,
 * ADR-083 decision 10) — not a plain `<a href>`, same Blob +
 * object-URL pattern as `downloadWalletTopupReceipt`.
 */
export async function downloadSupplierTransferReceipt(token: string, transferId: number, filename: string): Promise<void> {
  const response = await fetch(`${API_BASE_URL}/api/accounting/supplier-transfers/${transferId}/receipt`, {
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
