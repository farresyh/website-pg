import { apiFetch } from "@/lib/api-client";

export type VoucherStatus = "active" | "exhausted" | "expired" | "revoked" | "merged";

export interface Voucher {
  id: number;
  order_id: number | null;
  code: string;
  customer_email: string;
  amount: number;
  remaining: number;
  status: VoucherStatus;
  expires_at: string | null;
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
}

export interface VoucherShowResponse {
  voucher: VoucherDetail;
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
  amount: number;
  reason: string;
  expires_at?: string | null;
  idempotency_key: string;
}

export function listVouchers(token: string) {
  return apiFetch<VoucherIndexResponse>("/api/vouchers", { token });
}

export function getVoucher(token: string, id: number) {
  return apiFetch<VoucherShowResponse>(`/api/vouchers/${id}`, { token });
}

export function createVoucher(token: string, values: CreateVoucherValues) {
  return apiFetch<Voucher>("/api/vouchers", { method: "POST", token, body: values });
}

export interface MergeVouchersValues {
  voucher_ids: number[];
  reason: string;
  expires_at?: string | null;
}

export function mergeVouchers(token: string, values: MergeVouchersValues) {
  return apiFetch<Voucher>("/api/vouchers/merge", { method: "POST", token, body: values });
}

export function revokeVoucher(token: string, id: number) {
  return apiFetch<Voucher>(`/api/vouchers/${id}/revoke`, { method: "PATCH", token });
}
