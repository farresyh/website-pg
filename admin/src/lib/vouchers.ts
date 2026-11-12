import { apiDownload, apiFetch, apiUpload } from "@/lib/api-client";
import type { CustomerNotification } from "@/lib/orders";

export type VoucherStatus = "active" | "exhausted" | "expired" | "revoked" | "merged" | "refunded";

/** ADR-004 2026-11-12 addendum: a failed order's refund (the customer's own money) or gift credit. */
export type VoucherSource = "customer_cash" | "gift";

export interface Voucher {
  id: number;
  order_id: number | null;
  // ADR-060 PR-4d: the storefront brand this voucher is scoped to —
  // redeemable only on that brand.
  affiliate_id: number;
  affiliate?: { id: number; business_name: string } | null;
  code: string;
  /** Only `gift` can be revoked; only `customer_cash` can be refunded in cash. */
  source: VoucherSource;
  customer_email: string;
  amount: number;
  remaining: number;
  status: VoucherStatus;
  /** §16 item 84: `expired` for an active voucher past `expires_at`; use this for display and merge eligibility. */
  display_status: VoucherStatus;
  expires_at: string | null;
  revoked_at: string | null;
  revoked_reason: string | null;
  reason: string;
  created_by: number;
  approved_by: number | null;
  created_at: string;
}

export interface VoucherStat {
  count: number;
  total: number;
}

export interface VoucherIndexResponse {
  stats: {
    active: VoucherStat;
    total_issued: VoucherStat;
    total_used: VoucherStat;
    expired: VoucherStat;
  };
  vouchers: Voucher[];
}

export type VoucherRedemptionStatus = "reserved" | "committed" | "restored";

export interface VoucherRedemption {
  id: number;
  order_id: number;
  amount: number;
  status: VoucherRedemptionStatus;
  created_at: string;
  order: { id: number; order_number: string } | null;
}

export interface VoucherMergeRef {
  id: number;
  reason: string;
  merged_by: number | null;
  created_at: string;
  source_voucher_id: number;
  target_voucher_id: number;
  source_voucher?: { id: number; code: string };
  target_voucher?: { id: number; code: string };
}

export interface VoucherDetail extends Voucher {
  customer_phone: string | null;
  redemptions: VoucherRedemption[];
  source_order: { id: number; order_number: string } | null;
  // ADR-036 — provenance: which source codes fed into this voucher
  // (non-empty only for a merge's own result), or the single merge
  // that consumed this voucher as a source (non-null only once this
  // voucher's own status is "merged").
  merges_as_target: VoucherMergeRef[];
  merge_as_source: VoucherMergeRef | null;
  // ADR-116 decision 9 — WhatsApp messages sent (or skipped) about this voucher.
  customer_notifications: CustomerNotification[];
  cash_refund: VoucherCashRefund | null;
  revoked_by: { id: number; name: string } | null;
}

/** ADR-004 2026-11-12 addendum: the bank-transfer payout that closed a voucher. */
export interface VoucherCashRefund {
  id: number;
  amount_sen: number;
  transferred_on: string;
  bank_name: string;
  bank_account_no: string;
  bank_account_holder: string;
  bank_reference: string;
  reason: string;
  created_at: string;
  creator: { id: number; name: string } | null;
}

/** What the admin checks before a cash refund: the failed orders behind the voucher (decision 4). */
export interface RefundSourceOrder {
  id: number;
  order_number: string;
  delivery_status: string;
  late_supplier_result: Record<string, unknown> | null;
}

export interface VoucherShowResponse {
  voucher: VoucherDetail;
  refund_source_orders: RefundSourceOrder[];
  stats: {
    original: number;
    remaining: number;
    total_used: number;
    restored: number;
    pending: number;
    success_rate: number;
  };
}

export interface CreateVoucherValues {
  customer_email: string;
  // ADR-116: optional — when set, the code is sent to this number on WhatsApp.
  customer_phone?: string | null;
  amount: number;
  reason: string;
  expires_at?: string | null;
  idempotency_key: string;
  // ADR-060 PR-4d: required — the brand this voucher is redeemable on.
  affiliate_id: number;
}

export function listVouchers() {
  return apiFetch<VoucherIndexResponse>("/api/vouchers");
}

export function getVoucher(id: number) {
  return apiFetch<VoucherShowResponse>(`/api/vouchers/${id}`);
}

export function createVoucher(values: CreateVoucherValues) {
  return apiFetch<Voucher>("/api/vouchers", { method: "POST", body: values });
}

export interface MergeVouchersValues {
  voucher_ids: number[];
  reason: string;
  expires_at?: string | null;
}

export function mergeVouchers(values: MergeVouchersValues) {
  return apiFetch<Voucher>("/api/vouchers/merge", { method: "POST", body: values });
}

/** Gift credit only, with a reason. Super Admin, behind step-up (api-client prompts and retries). */
export function revokeVoucher(id: number, reason: string) {
  return apiFetch<Voucher>(`/api/vouchers/${id}/revoke`, { method: "PATCH", body: { reason } });
}

export interface CashRefundValues {
  transferred_on: string;
  bank_name: string;
  bank_account_no: string;
  bank_account_holder: string;
  bank_reference: string;
  reason: string;
  proof: File;
}

/** Record a bank transfer already sent. The amount is never sent: the server pays out the balance. */
export function refundVoucherInCash(id: number, values: CashRefundValues) {
  const formData = new FormData();
  Object.entries(values).forEach(([key, value]) => formData.append(key, value));
  return apiUpload<{ cash_refund: VoucherCashRefund; voucher: Voucher }>(`/api/vouchers/${id}/cash-refund`, formData);
}

export function downloadCashRefundProof(voucher: Pick<Voucher, "id" | "code">) {
  return apiDownload(`/api/vouchers/${voucher.id}/cash-refund/proof`, `voucher-${voucher.code}-refund-proof`);
}
