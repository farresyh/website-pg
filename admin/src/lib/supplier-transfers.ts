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
 *
 * 2026-09-28 addendum: `adjustments` no longer includes a transfer's
 * own void reversal (that's now a distinct `VOID_REVERSAL` ledger
 * entry server-side, invisible here) — a voided transfer's
 * `adjustments` are genuine prior corrections only. `corrections` is
 * new: every metadata-only "Edit Details" action against this transfer
 * (RM sent/fee/channel/reference/receipt) — never the ledger amounts.
 */
export interface SupplierTransfer {
  id: number;
  supplier_id: number;
  source_channel: "wise" | "airwallex" | "bank";
  /** 2026-09-30 addendum — which real account funded this, shared PaidFrom enum with the Envelope Ledger's paid_from. */
  paid_by: string | null;
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
  corrections: SupplierTransferCorrection[];
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

/** 2026-09-28 addendum — a metadata-only "Edit Details" action. `changes` is a JSON diff, one row per edit action (not per field): `{field: [old, new]}`. */
export interface SupplierTransferCorrection {
  id: number;
  supplier_transfer_id: number;
  changes: Record<string, [unknown, unknown]>;
  reason: string;
  admin_user_id: number | null;
  created_at: string;
}

export interface SupplierTransfersPage {
  data: SupplierTransfer[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface PaidFromOption {
  value: string;
  label: string;
}

export interface SupplierFundingLedger {
  ledger_balance: string;
  currency: string;
  transfers: SupplierTransfersPage;
  paid_from_options: PaidFromOption[];
}

/** 2026-09-28 addendum — `from`/`to` (`Y-m-d`) + `status` back the dedicated Funding History page's filters. */
export interface SupplierTransferFilters {
  from?: string;
  to?: string;
  status?: "active" | "voided";
  page?: number;
  per_page?: number;
}

export function getSupplierTransfers(token: string, supplierId: number, filters: SupplierTransferFilters = {}) {
  const params = new URLSearchParams();
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  if (filters.status) params.set("status", filters.status);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  const qs = params.toString();

  return apiFetch<SupplierFundingLedger>(`/api/accounting/suppliers/${supplierId}/transfers${qs ? `?${qs}` : ""}`, { token });
}

export interface RecordSupplierTransferValues {
  source_channel: "wise" | "airwallex" | "bank";
  paid_by?: string;
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
  if (values.paid_by) formData.append("paid_by", values.paid_by);
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

/** 2026-09-28 addendum — "Edit Details": a metadata-only correction (RM sent/fee/channel/reference/receipt), never the FX ledger amounts. Only send the fields actually changed — the backend diffs against the current row and rejects a true no-op. */
export interface CorrectSupplierTransferValues {
  source_channel?: "wise" | "airwallex" | "bank";
  paid_by?: string;
  amount_myr_sent?: number;
  fee_myr?: number;
  reference_no?: string;
  receipt?: File | null;
  reason: string;
}

export function correctSupplierTransfer(token: string, transferId: number, values: CorrectSupplierTransferValues) {
  const formData = new FormData();
  if (values.source_channel) formData.append("source_channel", values.source_channel);
  if (values.paid_by !== undefined) formData.append("paid_by", values.paid_by);
  if (values.amount_myr_sent !== undefined) formData.append("amount_myr_sent", String(values.amount_myr_sent));
  if (values.fee_myr !== undefined) formData.append("fee_myr", String(values.fee_myr));
  if (values.reference_no !== undefined) formData.append("reference_no", values.reference_no);
  if (values.receipt) formData.append("receipt", values.receipt);
  formData.append("reason", values.reason);

  return apiUpload<{ transfer: SupplierTransfer; correction: SupplierTransferCorrection }>(
    `/api/accounting/supplier-transfers/${transferId}/correct`,
    formData,
    { token },
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
