import { apiFetch, apiUpload, apiDownload } from "@/lib/api-client";


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
  /** ADR-083 2026-10-10 addendum, decision 15 — the KL day the money left the bank (Y-m-d). */
  transferred_on: string;
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

export function getSupplierTransfers(supplierId: number, filters: SupplierTransferFilters = {}) {
  const params = new URLSearchParams();
  if (filters.from) params.set("from", filters.from);
  if (filters.to) params.set("to", filters.to);
  if (filters.status) params.set("status", filters.status);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  const qs = params.toString();

  return apiFetch<SupplierFundingLedger>(`/api/accounting/suppliers/${supplierId}/transfers${qs ? `?${qs}` : ""}`);
}

export interface RecordSupplierTransferValues {
  /** Y-m-d, from the transfer receipt — required. */
  transferred_on: string;
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

export function recordSupplierTransfer(supplierId: number, values: RecordSupplierTransferValues) {
  const formData = new FormData();
  formData.append("transferred_on", values.transferred_on);
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
  );
}

/**
 * 2026-09-15 addendum — "Adjust": a partial, signed correction against
 * an already-recorded transfer (e.g. a missed supplier fee). Never
 * edits the transfer itself — writes a new `MANUAL_ADJUSTMENT` ledger
 * entry referencing it.
 */
export function adjustSupplierTransfer(transferId: number, amount: string, reason: string) {
  return apiFetch<{ entry: SupplierLedgerAdjustment; ledger_balance: string }>(
    `/api/accounting/supplier-transfers/${transferId}/adjust`,
    { method: "POST", body: { amount, reason } },
  );
}

/**
 * 2026-09-15 addendum — "Void Entirely": the money behind this
 * transfer never reached the supplier at all. Reverses its full net
 * ledger contribution and marks the transfer `voided_at` — never
 * possible to call twice on the same transfer (backend rejects once
 * `voided_at` is set).
 */
export function voidSupplierTransfer(transferId: number, reason: string) {
  return apiFetch<{ transfer: SupplierTransfer; entry: SupplierLedgerAdjustment; ledger_balance: string }>(
    `/api/accounting/supplier-transfers/${transferId}/void`,
    { method: "POST", body: { reason } },
  );
}

/** 2026-09-28 addendum — "Edit Details": a metadata-only correction (RM sent/fee/channel/reference/receipt), never the FX ledger amounts. Only send the fields actually changed — the backend diffs against the current row and rejects a true no-op. */
export interface CorrectSupplierTransferValues {
  transferred_on?: string;
  source_channel?: "wise" | "airwallex" | "bank";
  paid_by?: string;
  amount_myr_sent?: number;
  fee_myr?: number;
  reference_no?: string;
  receipt?: File | null;
  reason: string;
}

export function correctSupplierTransfer(transferId: number, values: CorrectSupplierTransferValues) {
  const formData = new FormData();
  if (values.transferred_on) formData.append("transferred_on", values.transferred_on);
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
  );
}

/**
 * Auth-gated route (`accounting_disk` is deliberately private,
 * ADR-083 decision 10) — not a plain `<a href>`, same Blob +
 * object-URL pattern as `downloadWalletTopupReceipt`.
 */
export async function downloadSupplierTransferReceipt(transferId: number, filename: string): Promise<void> {
  await apiDownload(`/api/accounting/supplier-transfers/${transferId}/receipt`, filename);
}
